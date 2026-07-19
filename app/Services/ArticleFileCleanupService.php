<?php

namespace App\Services;

use App\Models\ArticleFile;
use App\Models\MediaUploadSession;
use App\Models\ArticleVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArticleFileCleanupService
{
    public function audit(?int $articleId = null, array $fileIds = []): array
    {
        $files = ArticleFile::query()
            ->when($articleId, fn ($query) => $query->where('article_id', $articleId))
            ->when($fileIds, fn ($query) => $query->whereIn('id', $fileIds))
            ->orderBy('id')
            ->get();

        $duplicateGroups = $this->duplicateGroups($files)
            ->map(fn (Collection $group) => $this->duplicatePlan($group))
            ->values();
        $duplicateIds = $duplicateGroups->flatMap(fn ($group) => $group['record_ids'])->unique();
        $invalidRecords = $files
            ->reject(fn (ArticleFile $file) => $duplicateIds->contains($file->id))
            ->map(fn (ArticleFile $file) => $this->invalidPlan($file))
            ->filter()
            ->values();

        $actions = $duplicateGroups->flatMap(fn ($group) => $group['safe']
            ? collect($group['remove_ids'])->map(fn ($id) => [
                'type' => 'duplicate',
                'file_id' => $id,
                'canonical_id' => $group['canonical_id'],
                'reason' => $group['reason'],
            ])
            : [])->concat($invalidRecords->filter(fn ($record) => $record['safe'])->map(fn ($record) => [
                'type' => 'invalid',
                'file_id' => $record['file_id'],
                'canonical_id' => null,
                'reason' => $record['reason'],
            ]))->values();

        $multiplePrimaryManuscripts = $this->multiplePrimaryManuscripts($articleId);

        return [
            'duplicate_groups' => $duplicateGroups->all(),
            'invalid_records' => $invalidRecords->all(),
            'actions' => $actions->all(),
            'multiple_primary_manuscripts' => $multiplePrimaryManuscripts->all(),
            'manual_review_count' => $duplicateGroups->where('safe', false)->count()
                + $invalidRecords->where('safe', false)->count()
                + $multiplePrimaryManuscripts->where('manual_review_required', true)->count(),
        ];
    }

    private function multiplePrimaryManuscripts(?int $articleId): Collection
    {
        return ArticleVersion::query()
            ->when($articleId, fn ($query) => $query->where('article_id', $articleId))
            ->with(['files' => fn ($query) => $query
                ->where('file_type', ArticleFile::MANUSCRIPT)
                ->where('scan_status', 'clean')
                ->whereNull('assignment_type')])
            ->get()
            ->filter(fn (ArticleVersion $version) => $version->files->count() > 1)
            ->map(function (ArticleVersion $version) {
                $files = $version->files;
                $sameUpload = $files->pluck('media_upload_session_id')->filter()->unique()->count() === 1
                    && $files->every(fn (ArticleFile $file) => $file->media_upload_session_id);
                $sameStorage = $files->pluck('storage_key')->filter()->unique()->count() === 1
                    && $files->every(fn (ArticleFile $file) => $file->storage_key);
                $canonical = $version->manuscript_file_id && $files->contains('id', $version->manuscript_file_id)
                    ? $version->manuscript_file_id
                    : (($sameUpload || $sameStorage) ? $files->sortBy('id')->first()->id : null);

                return [
                    'category' => 'multiple_primary_manuscripts',
                    'article_id' => $version->article_id,
                    'article_version_id' => $version->id,
                    'version_label' => $version->label,
                    'manuscript_article_file_ids' => $files->pluck('id')->sort()->values()->all(),
                    'accepted_file_set_references' => DB::table('article_accepted_file_set_items')->whereIn('article_file_id', $files->pluck('id'))->pluck('article_file_id')->all(),
                    'workflow_production_references' => $files->filter(fn (ArticleFile $file) => $file->assignment_type || $file->assignment_id)->pluck('id')->all(),
                    'recommended_canonical_manuscript' => $canonical,
                    'manual_review_required' => !($sameUpload || $sameStorage),
                ];
            })->values();
    }

    public function apply(array $audit, string $performedBy = 'artisan'): array
    {
        $runId = (string) Str::uuid();
        $applied = [];
        $skipped = [];

        foreach ($audit['actions'] as $action) {
            try {
                $result = DB::transaction(fn () => $this->applyAction($action, $runId, $performedBy));
                if ($result) {
                    $applied[] = $result;
                } else {
                    $skipped[] = ['file_id' => $action['file_id'], 'reason' => 'Record changed or no longer qualifies.'];
                }
            } catch (\Throwable $exception) {
                Log::warning('article_file_cleanup.action_skipped', [
                    'cleanup_run_id' => $runId,
                    'removed_article_file_id' => $action['file_id'],
                    'reason' => $exception->getMessage(),
                ]);
                $skipped[] = ['file_id' => $action['file_id'], 'reason' => 'Cleanup validation failed; manual review required.'];
            }
        }

        return ['run_id' => $runId, 'applied' => $applied, 'skipped' => $skipped];
    }

    private function duplicateGroups(Collection $files): Collection
    {
        $groups = collect();
        $claimed = collect();
        $byUpload = $files->whereNotNull('media_upload_session_id')->groupBy('media_upload_session_id');
        foreach ($byUpload as $group) {
            if ($group->count() > 1) {
                $groups->push($group);
                $claimed = $claimed->merge($group->pluck('id'));
            }
        }

        $files->reject(fn ($file) => $claimed->contains($file->id))
            ->filter(fn ($file) => $file->storage_key)
            ->groupBy(fn ($file) => hash('sha256', $file->disk.'|'.$file->storage_key))
            ->each(function ($group) use ($groups) {
                if ($group->count() > 1) {
                    $groups->push($group);
                }
            });

        return $groups;
    }

    private function duplicatePlan(Collection $group): array
    {
        $ranked = $group->sortByDesc(fn (ArticleFile $file) => $this->canonicalScore($file))->values();
        $canonical = $ranked->first();
        $manualReasons = collect();
        if ($group->pluck('article_id')->unique()->count() !== 1) $manualReasons->push('Records belong to different articles.');
        if ($group->pluck('file_type')->unique()->count() !== 1) $manualReasons->push('Records have different purposes.');
        if ($group->pluck('storage_key')->filter()->unique()->count() > 1) $manualReasons->push('Upload-session records point to different storage objects.');
        if ($group->pluck('article_version_id')->filter()->unique()->count() > 1) $manualReasons->push('Records preserve different article versions.');
        if ($group->filter(fn ($file) => $file->assignment_type || $file->assignment_id)->count() > 1
            && $group->map(fn ($file) => $file->assignment_type.'|'.$file->assignment_id)->unique()->count() > 1) {
            $manualReasons->push('Records have conflicting workflow assignments.');
        }

        $removeIds = $ranked->slice(1)->pluck('id')->all();

        return [
            'article_id' => $canonical->article_id,
            'version_id' => $canonical->article_version_id,
            'file_type' => $canonical->file_type,
            'record_ids' => $group->pluck('id')->sort()->values()->all(),
            'canonical_id' => $canonical->id,
            'remove_ids' => $removeIds,
            'references_to_migrate' => collect($removeIds)->sum(fn ($id) => $this->referenceCount($id)),
            'storage_objects_affected' => 0,
            'safe' => $manualReasons->isEmpty(),
            'reason' => $manualReasons->isEmpty() ? 'authoritative_duplicate_reference' : $manualReasons->join(' '),
        ];
    }

    private function invalidPlan(ArticleFile $file): ?array
    {
        $upload = $file->media_upload_session_id ? MediaUploadSession::find($file->media_upload_session_id) : null;
        $objectExists = false;
        if ($file->storage_key) {
            try {
                $objectExists = Storage::disk($file->disk)->exists($file->storage_key);
            } catch (\Throwable) {
                $objectExists = false;
            }
        }
        $invalid = ($file->scan_status ?? 'clean') !== 'clean'
            || !$file->storage_key
            || !$objectExists
            || ($upload && ($upload->status !== MediaUploadSession::STATUS_CLEAN || !$upload->s3_clean_key));
        if (!$invalid) return null;

        $blocking = collect();
        if ($this->acceptedReferenceCount($file->id)) $blocking->push('Accepted File Set reference exists.');
        if ($file->article_version_id) $blocking->push('Article version linkage exists.');
        if ($file->assignment_type || $file->assignment_id) $blocking->push('Workflow assignment linkage exists.');
        if ($file->source_asset_id) {
            $assetStatus = DB::table('article_assets')->where('id', $file->source_asset_id)->value('scan_status');
            if (($assetStatus ?? 'clean') === 'clean') $blocking->push('A clean source asset reference exists.');
        }

        return [
            'article_id' => $file->article_id,
            'file_id' => $file->id,
            'scan_status' => $file->scan_status,
            'object_exists' => $objectExists,
            'safe' => $blocking->isEmpty(),
            'reason' => $blocking->isEmpty() ? 'invalid_or_nonready_file' : $blocking->join(' '),
        ];
    }

    private function canonicalScore(ArticleFile $file): float
    {
        return ($this->acceptedReferenceCount($file->id) ? 1000 : 0)
            + (($file->assignment_type || $file->assignment_id) ? 500 : 0)
            + (($file->scan_status ?? 'clean') === 'clean' ? 200 : 0)
            + ($file->media_upload_session_id && MediaUploadSession::whereKey($file->media_upload_session_id)->where('status', MediaUploadSession::STATUS_CLEAN)->exists() ? 100 : 0)
            + ($file->article_version_id ? 50 : 0)
            - $file->id / 1000000;
    }

    private function applyAction(array $action, string $runId, string $performedBy): ?array
    {
        $file = ArticleFile::query()->whereKey($action['file_id'])->lockForUpdate()->first();
        if (!$file) return null;
        $references = [];
        $canonical = null;

        if ($action['type'] === 'duplicate') {
            $canonical = ArticleFile::query()->whereKey($action['canonical_id'])->lockForUpdate()->first();
            if (!$canonical || $canonical->article_id !== $file->article_id || $canonical->file_type !== $file->file_type
                || ($canonical->disk.'|'.$canonical->storage_key) !== ($file->disk.'|'.$file->storage_key)) return null;
            $references = $this->migrateReferences($file, $canonical);
        } else {
            $freshPlan = $this->invalidPlan($file);
            if (!$freshPlan || !$freshPlan['safe']) return null;
        }

        DB::table('article_file_cleanup_logs')->insert([
            'cleanup_run_id' => $runId,
            'article_id' => $file->article_id,
            'canonical_article_file_id' => $canonical?->id,
            'removed_article_file_id' => $file->id,
            'reason' => $action['reason'],
            'references_migrated' => json_encode($references, JSON_THROW_ON_ERROR),
            'storage_deleted' => false,
            'performed_by' => $performedBy,
            'performed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $removedId = $file->id;
        $articleId = $file->article_id;
        $file->delete();
        Log::info('article_file_cleanup.removed', [
            'cleanup_run_id' => $runId,
            'article_id' => $articleId,
            'canonical_article_file_id' => $canonical?->id,
            'removed_article_file_id' => $removedId,
            'reason' => $action['reason'],
            'references_migrated' => $references,
            'storage_deleted' => false,
            'performed_by' => $performedBy,
        ]);

        return ['removed_id' => $removedId, 'canonical_id' => $canonical?->id, 'references_migrated' => $references];
    }

    private function migrateReferences(ArticleFile $from, ArticleFile $to): array
    {
        $migrated = ['accepted_file_set_items' => 0, 'upload_sessions' => 0, 'version_snapshots' => 0, 'version_manuscript_pointer' => 0, 'workflow_assignment' => 0];
        $items = DB::table('article_accepted_file_set_items')->where('article_file_id', $from->id)->get();
        foreach ($items as $item) {
            $conflict = DB::table('article_accepted_file_set_items')
                ->where('accepted_file_set_id', $item->accepted_file_set_id)
                ->where('article_file_id', $to->id)->exists();
            if ($conflict) {
                DB::table('article_accepted_file_set_items')->where('id', $item->id)->delete();
            } else {
                DB::table('article_accepted_file_set_items')->where('id', $item->id)->update(['article_file_id' => $to->id]);
            }
            $migrated['accepted_file_set_items']++;
        }
        if (($from->assignment_type || $from->assignment_id) && !$to->assignment_type && !$to->assignment_id) {
            $to->update(['assignment_type' => $from->assignment_type, 'assignment_id' => $from->assignment_id]);
            $migrated['workflow_assignment']++;
        }
        if ($from->source_asset_id && !$to->source_asset_id) $to->update(['source_asset_id' => $from->source_asset_id]);
        MediaUploadSession::query()->get()->each(function ($upload) use ($from, $to, &$migrated) {
            if ((int) data_get($upload->metadata, 'article_file_id') === $from->id) {
                $metadata = $upload->metadata ?: [];
                $metadata['article_file_id'] = $to->id;
                $upload->update(['metadata' => $metadata]);
                $migrated['upload_sessions']++;
            }
        });
        DB::table('article_versions')->where('article_id', $from->article_id)->get()->each(function ($version) use ($from, $to, &$migrated) {
            if ((int) ($version->manuscript_file_id ?? 0) === $from->id) {
                DB::table('article_versions')->where('id', $version->id)->update(['manuscript_file_id' => $to->id]);
                $migrated['version_manuscript_pointer']++;
            }
            $snapshot = json_decode($version->file_snapshot ?: '[]', true) ?: [];
            $changed = false;
            foreach ($snapshot as &$entry) {
                if ((int) ($entry['id'] ?? 0) === $from->id) {
                    $entry['id'] = $to->id;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('article_versions')->where('id', $version->id)->update(['file_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                $migrated['version_snapshots']++;
            }
        });

        return $migrated;
    }

    private function acceptedReferenceCount(int $fileId): int
    {
        return DB::table('article_accepted_file_set_items')->where('article_file_id', $fileId)->count();
    }

    private function referenceCount(int $fileId): int
    {
        return $this->acceptedReferenceCount($fileId)
            + MediaUploadSession::query()->get()->filter(fn ($upload) => (int) data_get($upload->metadata, 'article_file_id') === $fileId)->count();
    }
}
