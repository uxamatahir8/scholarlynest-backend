<?php

namespace App\Services;

use App\Constants\DirectPublicationStatus;
use App\Http\Controllers\ArticleFileController;
use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\ArticleAuthor;
use App\Models\ArticleFile;
use App\Models\ArticlePublicationSection;
use App\Models\ArticleVersion;
use App\Models\PublicationFileSelection;
use App\Models\PublicationRecord;
use App\Models\User;
use App\Models\WorkflowIdempotencyKey;
use App\Services\Media\CleanUploadResolver;
use App\Services\Notifications\NotificationEventRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DirectPublicationService
{
    private const EDITABLE = [DirectPublicationStatus::DRAFT, DirectPublicationStatus::READY];

    public function __construct(
        private CleanUploadResolver $uploads,
        private ArticleFileController $articleFiles,
        private NotificationEventRecorder $notifications,
    ) {}

    public function createDraft(User $actor, array $payload, string $key): Article
    {
        return DB::transaction(function () use ($actor, $payload, $key) {
            if ($existing = $this->idempotentResult($actor, 'direct.create', $key, $payload)) {
                return $existing;
            }

            $slug = $this->uniqueSlug($payload['slug'] ?? $payload['title']);
            $article = Article::create(array_merge($this->articleData($payload), [
                'magazine_id' => $payload['magazine_id'],
                'user_id' => null,
                'title' => $payload['title'],
                'slug' => $slug,
                'abstract' => $payload['abstract'],
                'full_text' => $payload['full_text'] ?? '',
                'status' => DirectPublicationStatus::DRAFT,
                'lifecycle_status' => DirectPublicationStatus::DRAFT,
                'submission_mode' => 'direct_publication',
                'directly_created_by' => $actor->id,
                'is_peer_reviewed' => false,
            ]));

            $version = ArticleVersion::create([
                'article_id' => $article->id, 'created_by' => $actor->id,
                'version_number' => 1, 'revision_number' => 0,
                'label' => 'Initial Publication Version', 'source' => 'direct_publication',
                'status_snapshot' => DirectPublicationStatus::DRAFT,
                'metadata_snapshot' => $this->metadataSnapshot($article),
            ]);
            $article->forceFill(['current_version_id' => $version->id])->save();
            $this->replaceAuthors($article, $payload['authors'] ?? []);
            $this->persistPublicationSections($article, $payload['publication_sections'] ?? [], $actor);

            PublicationRecord::create([
                'article_id' => $article->id, 'magazine_id' => $article->magazine_id,
                'article_version_id' => $version->id, 'publication_mode' => 'direct',
                'magazine_issue_id' => $payload['magazine_issue_id'] ?? null,
                'status' => DirectPublicationStatus::DRAFT, 'active_marker' => 1,
                'doi' => $payload['doi'] ?? null, 'page_start' => $payload['page_start'] ?? null,
                'page_end' => $payload['page_end'] ?? null,
                'online_publication_date' => $payload['online_publication_date'] ?? null,
                'print_publication_date' => $payload['print_publication_date'] ?? null,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);

            $this->audit($article, $actor, 'direct_publication.created', null, DirectPublicationStatus::DRAFT, [], $payload);
            $this->notify('direct_publication.created', $article, $actor, $key);
            $this->completeIdempotency($actor, 'direct.create', $key, $payload, $article);

            return $this->fresh($article);
        });
    }

    public function updateDraft(Article $article, User $actor, array $payload, string $key): Article
    {
        return $this->mutate($article, $actor, 'direct.update', $key, $payload, function (Article $locked) use ($actor, $payload) {
            $this->assertStatus($locked, self::EDITABLE);
            $before = $locked->only(array_keys($this->articleData($payload)));
            $locked->fill($this->articleData($payload));
            if (isset($payload['slug']) && $payload['slug'] !== $locked->getOriginal('slug')) {
                $locked->slug = $this->uniqueSlug($payload['slug'], $locked->id);
            }
            $locked->save();
            if (array_key_exists('authors', $payload)) {
                $this->replaceAuthors($locked, $payload['authors']);
            }
            if (array_key_exists('publication_sections', $payload)) {
                $this->persistPublicationSections($locked, $payload['publication_sections'], $actor);
            }
            $publication = $this->record($locked, true);
            $publication->fill(Arr::only($payload, [
                'magazine_issue_id', 'doi', 'page_start', 'page_end', 'online_publication_date', 'print_publication_date',
            ]) + ['magazine_id' => $locked->magazine_id, 'updated_by' => $actor->id])->save();
            $locked->load('currentVersion');
            $locked->currentVersion?->forceFill(['metadata_snapshot' => $this->metadataSnapshot($locked->fresh())])->save();
            $this->audit($locked, $actor, 'direct_publication.metadata_updated', $locked->status, $locked->status, $before, $payload);
        });
    }

    public function attachFile(Article $article, User $actor, array $payload, string $key): ArticleFile
    {
        return DB::transaction(function () use ($article, $actor, $payload, $key) {
            $hash = hash('sha256', json_encode($payload));
            $idempotency = WorkflowIdempotencyKey::where('actor_id', $actor->id)->where('command', 'direct.file.attach')
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($idempotency) {
                if (! hash_equals($idempotency->request_hash, $hash)) {
                    abort(409, 'The idempotency key was already used with a different request.');
                }

                return ArticleFile::findOrFail($idempotency->response_payload['article_file_id']);
            }
            $locked = Article::query()->lockForUpdate()->findOrFail($article->id);
            $allowed = self::EDITABLE;
            if ($payload['purpose'] === 'direct_publication_manuscript') {
                $allowed[] = DirectPublicationStatus::PUBLISHED;
            }
            $this->assertStatus($locked, $allowed);
            $purposeConfig = config("media_uploads.purposes.{$payload['purpose']}");
            abort_unless(is_array($purposeConfig), 422, 'Unsupported direct-publication file purpose.');
            $upload = $this->uploads->resolveOwned($actor, $payload['upload_id'], $payload['purpose']);
            $file = $this->articleFiles->createCleanDirectUploadFile($locked, $upload, $purposeConfig, [
                'article_version_id' => $locked->current_version_id,
                'file_title' => $payload['file_title'] ?? null,
                'metadata' => ['direct_publication' => true],
            ]);
            $file->forceFill(['visibility' => 'internal'])->save();
            $this->audit($locked, $actor, 'direct_publication.file_uploaded', $locked->status, $locked->status, [], ['article_file_id' => $file->id]);
            WorkflowIdempotencyKey::create(['article_id' => $locked->id, 'actor_id' => $actor->id, 'command' => 'direct.file.attach',
                'idempotency_key' => $key, 'request_hash' => $hash, 'response_status' => 201, 'response_payload' => ['article_file_id' => $file->id]]);

            return $file->fresh();
        });
    }

    public function deleteFile(Article $article, ArticleFile $file, User $actor, string $key): Article
    {
        abort_unless((int) $file->article_id === (int) $article->id, 404);

        return $this->mutate($article, $actor, 'direct.file.delete', $key, ['file_id' => $file->id], function (Article $locked) use ($file, $actor) {
            $this->assertStatus($locked, self::EDITABLE);
            $record = $this->record($locked, true);
            if ((int) $record->primary_publication_file_id === (int) $file->id) {
                throw ValidationException::withMessages(['file' => 'Deselect the primary publication PDF before deleting it.']);
            }
            PublicationFileSelection::where('publication_record_id', $record->id)->where('article_file_id', $file->id)->delete();
            $file->delete();
            $this->audit($locked, $actor, 'direct_publication.file_deleted', $locked->status, $locked->status, ['article_file_id' => $file->id], []);
        });
    }

    public function selectPrimary(Article $article, User $actor, int $fileId, string $key): Article
    {
        return $this->mutate($article, $actor, 'direct.primary.select', $key, ['file_id' => $fileId], function (Article $locked) use ($actor, $fileId) {
            $this->assertStatus($locked, [DirectPublicationStatus::DRAFT, DirectPublicationStatus::READY, DirectPublicationStatus::PUBLISHED]);
            $file = $locked->files()->whereKey($fileId)->where('file_type', ArticleFile::DIRECT_PUBLICATION_MANUSCRIPT)
                ->where('mime_type', 'application/pdf')->where('scan_status', 'clean')->first();
            if (! $file) {
                throw ValidationException::withMessages(['article_file_id' => 'Select a clean direct-publication PDF owned by this article.']);
            }
            $record = $this->record($locked, true);
            $previousFileId = $record->primary_publication_file_id;
            $record->files()->update(['is_primary' => false, 'primary_marker' => null]);
            $record->files()->updateOrCreate(['article_file_id' => $file->id], [
                'public_role' => 'primary_pdf', 'is_primary' => true, 'is_public' => true,
                'primary_marker' => 1, 'selected_by' => $actor->id,
            ]);
            $record->update(['primary_publication_file_id' => $file->id, 'updated_by' => $actor->id]);
            $event = $locked->status === DirectPublicationStatus::PUBLISHED ? 'direct_publication.file_replaced' : 'direct_publication.primary_file_selected';
            if ($locked->status === DirectPublicationStatus::PUBLISHED) {
                $locked->forceFill(['pdf_path' => $file->storage_key ?: $file->file_path])->save();
                $this->notify($event, $locked, $actor, $key);
            }
            $this->audit($locked, $actor, $event, $locked->status, $locked->status, ['article_file_id' => $previousFileId], ['article_file_id' => $file->id]);
        });
    }

    public function selectPublicAssets(Article $article, User $actor, array $settings, string $key): Article
    {
        return $this->mutate($article, $actor, 'direct.assets.select', $key, ['settings' => $settings], function (Article $locked) use ($actor, $settings) {
            $this->assertStatus($locked, [DirectPublicationStatus::DRAFT, DirectPublicationStatus::READY, DirectPublicationStatus::SCHEDULED, DirectPublicationStatus::PUBLISHED]);
            $record = $this->record($locked, true);
            $fileIds = collect($settings)->pluck('file_id')->map(fn ($id) => (int) $id)->unique()->values();
            $files = $locked->files()->whereIn('id', $fileIds)->where('scan_status', 'clean')->get()->keyBy('id');
            if ($files->count() !== $fileIds->count()) {
                throw ValidationException::withMessages(['publication_file_settings' => 'One or more file selections are invalid or do not belong to this article.']);
            }
            $record->files()->where('is_primary', false)->delete();
            foreach ($settings as $setting) {
                $file = $files->get((int) $setting['file_id']);
                $visibility = [
                    'show_on_article' => (bool) ($setting['show_on_article'] ?? false),
                    'show_in_downloads' => (bool) ($setting['show_in_downloads'] ?? false),
                    'include_in_package' => (bool) ($setting['include_in_package'] ?? false),
                ];
                $metadata = $file->metadata ?: [];
                $metadata['publication_visibility'] = $visibility;
                $file->update(['metadata' => $metadata]);
                $isPublic = ($visibility['show_on_article'] || $visibility['show_in_downloads'])
                    && in_array($file->file_type, [ArticleFile::DIRECT_PUBLICATION_FIGURE, ArticleFile::DIRECT_PUBLICATION_SUPPLEMENTARY, ArticleFile::DIRECT_PUBLICATION_COVER], true);
                if ($isPublic) {
                    $record->files()->create(['article_file_id' => $file->id, 'public_role' => match ($file->file_type) {
                        ArticleFile::DIRECT_PUBLICATION_FIGURE => 'figure', ArticleFile::DIRECT_PUBLICATION_COVER => 'cover', default => 'supplementary',
                    }, 'is_primary' => false, 'is_public' => true, 'selected_by' => $actor->id]);
                }
            }
            $this->audit($locked, $actor, 'direct_publication.public_assets_selected', $locked->status, $locked->status, [], ['publication_file_settings' => $settings]);
        });
    }

    public function readiness(Article $article): array
    {
        $article->loadMissing(['articleAuthors', 'files', 'magazine', 'currentVersion']);
        $record = $this->record($article)->loadMissing(['issue', 'primaryFile']);
        $errors = [];
        foreach (['title', 'abstract', 'article_type', 'language'] as $field) {
            if (blank($article->{$field})) {
                $errors[$field][] = ucfirst(str_replace('_', ' ', $field)).' is required.';
            }
        }
        if ($article->articleAuthors->isEmpty()) {
            $errors['authors'][] = 'At least one author is required.';
        }
        if (! $article->articleAuthors->contains('is_corresponding', true)) {
            $errors['corresponding_author'][] = 'A corresponding author is required.';
        }
        if ($article->articleAuthors->contains(fn ($author) => blank($author->affiliation))) {
            $errors['affiliations'][] = 'Every author must have an affiliation.';
        }
        if (! $record->magazine_issue_id) {
            $errors['magazine_issue_id'][] = 'An issue must be selected.';
        } elseif (! $record->issue || (int) $record->issue->magazine_id !== (int) $article->magazine_id) {
            $errors['magazine_issue_id'][] = 'The issue must belong to this publication.';
        }
        if (blank($record->doi)) {
            $errors['doi'][] = 'DOI is required.';
        }
        if (! $record->page_start || ! $record->page_end || $record->page_start > $record->page_end) {
            $errors['page_range'][] = 'A valid page range is required.';
        }
        if (! $record->online_publication_date) {
            $errors['online_publication_date'][] = 'Online publication date is required.';
        }
        if (! $record->primaryFile || $record->primaryFile->file_type !== ArticleFile::DIRECT_PUBLICATION_MANUSCRIPT || $record->primaryFile->mime_type !== 'application/pdf' || $record->primaryFile->scan_status !== 'clean') {
            $errors['primary_publication_file'][] = 'A clean final publication PDF must be selected.';
        }
        if ($record->doi && PublicationRecord::where('doi', $record->doi)->whereKeyNot($record->id)->exists()) {
            $errors['doi'][] = 'This DOI is already in use.';
        }
        if ($record->magazine_issue_id && $record->page_start && $record->page_end && PublicationRecord::where('magazine_issue_id', $record->magazine_issue_id)
            ->whereKeyNot($record->id)->whereNotNull('active_marker')->where('page_start', '<=', $record->page_end)->where('page_end', '>=', $record->page_start)->exists()) {
            $errors['page_range'][] = 'The page range overlaps another article in this issue.';
        }

        return ['ready' => $errors === [], 'code' => $errors === [] ? null : 'DIRECT_PUBLICATION_NOT_READY', 'errors' => $errors];
    }

    public function markReady(Article $article, User $actor, string $key): Article
    {
        return $this->transition($article, $actor, 'direct.ready', $key, DirectPublicationStatus::READY, function (Article $locked) {
            $this->assertStatus($locked, [DirectPublicationStatus::DRAFT, DirectPublicationStatus::READY]);
            $this->assertReady($locked);
            $locked->direct_publication_ready_at = now();
            $locked->currentVersion?->forceFill(['locked_at' => now(), 'status_snapshot' => DirectPublicationStatus::READY])->save();
        });
    }

    public function moveToDraft(Article $article, User $actor, string $key): Article
    {
        return $this->transition($article, $actor, 'direct.return_to_draft', $key, DirectPublicationStatus::DRAFT, function (Article $locked) {
            $this->assertStatus($locked, [DirectPublicationStatus::READY]);
            $locked->direct_publication_ready_at = null;
            $locked->currentVersion?->forceFill(['locked_at' => null, 'status_snapshot' => DirectPublicationStatus::DRAFT])->save();
        });
    }

    public function schedule(Article $article, User $actor, CarbonInterface $at, string $key): Article
    {
        return $this->transition($article, $actor, 'direct.schedule', $key, DirectPublicationStatus::SCHEDULED, function (Article $locked) use ($at, $actor) {
            $this->assertStatus($locked, [DirectPublicationStatus::READY, DirectPublicationStatus::SCHEDULED]);
            $this->assertReady($locked);
            $this->record($locked, true)->update(['scheduled_for' => $at, 'updated_by' => $actor->id]);
        }, ['scheduled_at' => $at->toIso8601String()]);
    }

    public function unschedule(Article $article, User $actor, string $key): Article
    {
        return $this->transition($article, $actor, 'direct.unscheduled', $key, DirectPublicationStatus::READY, function (Article $locked) use ($actor) {
            $this->assertStatus($locked, [DirectPublicationStatus::SCHEDULED]);
            $this->record($locked, true)->update(['scheduled_for' => null, 'updated_by' => $actor->id,
                'publication_failed_at' => null, 'publication_failure_code' => null, 'publication_failure_message' => null]);
        });
    }

    public function correctPublishedMetadata(Article $article, User $actor, array $payload, string $key): Article
    {
        return $this->mutate($article, $actor, 'direct.metadata.correct', $key, $payload, function (Article $locked) use ($actor, $payload, $key) {
            $this->assertStatus($locked, [DirectPublicationStatus::PUBLISHED]);
            $record = $this->record($locked, true);
            $old = $record->only(['magazine_issue_id', 'doi', 'page_start', 'page_end', 'online_publication_date', 'print_publication_date']);
            $record->fill(Arr::only($payload, array_keys($old)) + ['updated_by' => $actor->id])->save();
            $locked->fill(Arr::except($this->articleData($payload), ['magazine_id']));
            $locked->forceFill(['magazine_issue_id' => $record->magazine_issue_id, 'doi' => $record->doi,
                'page_start' => $record->page_start, 'page_end' => $record->page_end])->save();
            if (array_key_exists('authors', $payload)) {
                $this->replaceAuthors($locked, $payload['authors']);
            }
            $this->assertReady($locked);
            if (array_key_exists('publication_sections', $payload)) {
                $this->persistPublicationSections($locked, $payload['publication_sections'], $actor);
            }
            $this->audit($locked, $actor, 'direct_publication.metadata_corrected', $locked->status, $locked->status, $old, $payload);
            $this->notify('direct_publication.metadata_corrected', $locked, $actor, $key);
        });
    }

    public function publish(Article $article, User $actor, string $key): Article
    {
        return $this->transition($article, $actor, 'direct.publish', $key, DirectPublicationStatus::PUBLISHED, function (Article $locked) use ($actor) {
            $this->assertStatus($locked, [DirectPublicationStatus::READY, DirectPublicationStatus::SCHEDULED]);
            $this->assertReady($locked);
            $record = $this->record($locked, true);
            $at = now();
            $record->update(['status' => DirectPublicationStatus::PUBLISHED, 'published_at' => $at, 'published_by' => $actor->id,
                'scheduled_for' => null, 'publication_failed_at' => null, 'publication_failure_code' => null, 'publication_failure_message' => null]);
            $locked->forceFill(['pdf_path' => $record->primaryFile?->storage_key, 'magazine_issue_id' => $record->magazine_issue_id,
                'doi' => $record->doi, 'page_start' => $record->page_start, 'page_end' => $record->page_end,
                'published_at' => $at, 'published_year' => $at->year, 'published_month' => $at->month])->save();
        });
    }

    public function unpublish(Article $article, User $actor, string $reason, string $key): Article
    {
        return $this->transition($article, $actor, 'direct.unpublish', $key, DirectPublicationStatus::UNPUBLISHED, function (Article $locked) use ($actor, $reason) {
            $this->assertStatus($locked, [DirectPublicationStatus::PUBLISHED]);
            $this->record($locked, true)->update(['status' => DirectPublicationStatus::UNPUBLISHED, 'active_marker' => null,
                'unpublished_at' => now(), 'unpublished_by' => $actor->id, 'unpublish_reason' => $reason, 'updated_by' => $actor->id]);
            $locked->forceFill(['pdf_path' => null])->save();
        }, ['reason' => $reason]);
    }

    public function deleteDraft(Article $article, User $actor): void
    {
        DB::transaction(function () use ($article, $actor) {
            $locked = Article::query()->lockForUpdate()->findOrFail($article->id);
            $this->assertStatus($locked, [DirectPublicationStatus::DRAFT]);
            $this->audit($locked, $actor, 'direct_publication.draft_deleted', $locked->status, null, [], []);
            $locked->delete();
        });
    }

    private function transition(Article $article, User $actor, string $command, string $key, string $to, callable $action, array $payload = []): Article
    {
        return $this->mutate($article, $actor, $command, $key, $payload, function (Article $locked) use ($actor, $to, $action, $command, $key) {
            $from = $locked->status;
            $action($locked);
            $locked->forceFill(['status' => $to, 'lifecycle_status' => $to])->save();
            $record = $this->record($locked, true);
            if ($to !== DirectPublicationStatus::PUBLISHED && $to !== DirectPublicationStatus::UNPUBLISHED) {
                $record->update(['status' => $to, 'updated_by' => $actor->id]);
            }
            $event = match ($command) {
                'direct.ready' => 'direct_publication.ready',
                'direct.schedule' => 'direct_publication.scheduled',
                'direct.publish' => 'direct_publication.published',
                'direct.unpublish' => 'direct_publication.unpublished',
                default => str_replace('direct.', 'direct_publication.', $command),
            };
            $this->audit($locked, $actor, $event, $from, $to, [], []);
            $this->notify($event, $locked, $actor, $key);
        });
    }

    private function mutate(Article $article, User $actor, string $command, string $key, array $payload, callable $action): Article
    {
        return DB::transaction(function () use ($article, $actor, $command, $key, $payload, $action) {
            if ($existing = $this->idempotentResult($actor, $command, $key, $payload)) {
                return $existing;
            }
            $locked = Article::query()->lockForUpdate()->findOrFail($article->id);
            abort_unless($locked->isDirectPublication(), 404);
            $action($locked);
            $this->completeIdempotency($actor, $command, $key, $payload, $locked);

            return $this->fresh($locked);
        });
    }

    private function assertReady(Article $article): void
    {
        $result = $this->readiness($article->fresh());
        if (! $result['ready']) {
            throw ValidationException::withMessages($result['errors']);
        }
    }

    private function replaceAuthors(Article $article, array $authors): void
    {
        $article->articleAuthors()->delete();
        foreach (array_values($authors) as $index => $author) {
            $email = Str::lower(trim($author['email']));
            ArticleAuthor::create([
                'article_id' => $article->id, 'user_id' => User::whereRaw('LOWER(email) = ?', [$email])->value('id'),
                'co_author_name' => $author['name'], 'co_author_email' => $email, 'affiliation' => $author['affiliation'],
                'department' => $author['department'] ?? null, 'country' => $author['country'] ?? null, 'orcid' => $author['orcid'] ?? null,
                'author_order' => $index + 1, 'is_owner' => $index === 0, 'is_corresponding' => (bool) ($author['is_corresponding'] ?? false),
                'can_edit' => false, 'account_provisioned' => false,
            ]);
        }
    }

    private function persistPublicationSections(Article $article, array $sections, User $actor): void
    {
        if (! collect($sections)->contains(fn ($section) => ($section['section_key'] ?? null) === 'abstract')) {
            array_unshift($sections, [
                'section_key' => 'abstract', 'title' => 'Abstract',
                'content_html' => $article->abstract ?: '', 'sort_order' => 1,
            ]);
        }

        $sections = collect(array_values($sections))
            ->sortBy(fn ($section, $index) => (int) ($section['sort_order'] ?? ($index + 1)))
            ->values();
        $keptIds = [];
        foreach ($sections as $index => $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $html = $this->sanitizeRichText((string) ($section['content_html'] ?? ''));
            if ($title === '' && $html === '') {
                continue;
            }
            $key = str_replace('-', '_', Str::limit(Str::slug((string) ($section['section_key'] ?? $title)) ?: 'section-'.($index + 1), 120, ''));
            $existing = ArticlePublicationSection::where('article_id', $article->id)->where('section_key', $key)->first();
            $uploadId = $section['media_upload_session_id'] ?? null;
            if ($uploadId && (string) $existing?->media_upload_session_id !== (string) $uploadId) {
                $this->uploads->resolveOwned($actor, $uploadId, 'publication_section_image');
            }
            $record = ArticlePublicationSection::updateOrCreate(
                ['article_id' => $article->id, 'section_key' => $key],
                ['title' => $title ?: Str::headline($key), 'content_html' => $html,
                    'content_text' => trim(html_entity_decode(strip_tags($html))), 'sort_order' => $index + 1,
                    'media_upload_session_id' => $uploadId ?: $existing?->media_upload_session_id]
            );
            $keptIds[] = $record->id;
        }
        $article->publicationSections()->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))->delete();
    }

    private function sanitizeRichText(string $html): string
    {
        $allowed = '<p><br><strong><b><em><i><u><ol><ul><li><blockquote><a><h2><h3><h4><table><thead><tbody><tr><th><td><sup><sub>';
        $clean = strip_tags($html, $allowed);
        $clean = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
        $clean = preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', 'href="#"', $clean) ?? '';

        return trim($clean);
    }

    private function articleData(array $payload): array
    {
        return Arr::only($payload, ['magazine_id', 'title', 'subtitle', 'abstract', 'keywords', 'article_category', 'article_type', 'subject_area',
            'language', 'ethical_approval_statement', 'conflict_of_interest_statement', 'funding_statement', 'data_availability_statement',
            'author_contribution_statement', 'license_statement', 'citation_text', 'abbreviations', 'full_text', 'open_access_label',
            'academic_editor', 'received_at', 'competing_interests_statement']);
    }

    private function metadataSnapshot(Article $article): array
    {
        return $article->only(array_merge(['title', 'slug'], array_keys($this->articleData($article->toArray()))));
    }

    private function record(Article $article, bool $lock = false): PublicationRecord
    {
        return $article->publicationRecords()->where('publication_mode', 'direct')->when($lock, fn ($q) => $q->lockForUpdate())->latest('id')->firstOrFail();
    }

    private function fresh(Article $article): Article
    {
        return $article->fresh(['magazine', 'articleAuthors', 'files', 'publicationSections', 'currentVersion', 'latestPublicationRecord.issue', 'latestPublicationRecord.primaryFile', 'latestPublicationRecord.files.file']);
    }

    private function assertStatus(Article $article, array $allowed): void
    {
        if (! in_array($article->status, $allowed, true)) {
            abort(409, 'This action is not allowed in the current direct-publication status.');
        }
    }

    private function uniqueSlug(string $value, ?int $ignore = null): string
    {
        $base = Str::slug($value) ?: 'direct-publication';
        $slug = $base;
        $i = 2;
        while (Article::where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function audit(Article $article, User $actor, string $event, ?string $from, ?string $to, array $old, array $new): void
    {
        ArticleAuditLog::create(['article_id' => $article->id, 'actor_id' => $actor->id, 'event' => $event,
            'from_status' => $from, 'to_status' => $to, 'payload' => ['actor_role' => $actor->role?->name, 'old' => $old, 'new' => $new]]);
    }

    private function notify(string $event, Article $article, User $actor, string $key): void
    {
        app(ArticleThreadSystemEventService::class)->recordLifecycleEvent($article, $event, $actor, 'direct:'.$article->id.':'.$event.':'.$key);
        if (config("notification_system.templates.{$event}")) {
            $this->notifications->record($event, $article, $actor,
                ['status' => $article->status], 'article', $article->id, deduplicationKey: "direct:{$article->id}:{$event}:{$key}");
        }
    }

    private function idempotentResult(User $actor, string $command, string $key, array $payload): ?Article
    {
        $row = WorkflowIdempotencyKey::where('actor_id', $actor->id)->where('command', $command)->where('idempotency_key', $key)->lockForUpdate()->first();
        if (! $row) {
            return null;
        }
        if (! hash_equals($row->request_hash, hash('sha256', json_encode($payload)))) {
            abort(409, 'The idempotency key was already used with a different request.');
        }

        return isset($row->response_payload['article_id']) ? $this->fresh(Article::findOrFail($row->response_payload['article_id'])) : null;
    }

    private function completeIdempotency(User $actor, string $command, string $key, array $payload, Article $article): void
    {
        WorkflowIdempotencyKey::updateOrCreate(['actor_id' => $actor->id, 'command' => $command, 'idempotency_key' => $key], [
            'article_id' => $article->id, 'request_hash' => hash('sha256', json_encode($payload)), 'response_status' => 200,
            'response_payload' => ['article_id' => $article->id],
        ]);
    }
}
