<?php

namespace App\Http\Controllers\Admin;

use App\Constants\DirectPublicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DirectPublicationRequest;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Policies\DirectPublicationPolicy;
use App\Services\DirectPublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DirectPublicationController extends Controller
{
    public function __construct(private DirectPublicationService $service, private DirectPublicationPolicy $policy) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->policy->viewAny($request->user()), 403);
        $base = Article::query()->where('submission_mode', 'direct_publication');
        if (! $request->user()->hasRole('super_admin')) {
            $base->whereIn('magazine_id', $this->publisherMagazineIds($request));
        }
        $counts = collect(DirectPublicationStatus::ALL)->mapWithKeys(fn ($status) => [$status => (clone $base)->where('status', $status)->count()]);
        $counts['blocked_by_validation'] = (clone $base)->whereIn('status', [DirectPublicationStatus::DRAFT, DirectPublicationStatus::READY])
            ->get()->filter(fn (Article $article) => ! $this->service->readiness($article)['ready'])->count();

        $query = (clone $base)->with(['magazine:id,title,publication_type', 'articleAuthors', 'latestPublicationRecord.issue', 'latestPublicationRecord.primaryFile']);
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('magazine_id'), fn ($q) => $q->where('magazine_id', $request->integer('magazine_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($nested) => $nested->where('title', 'like', '%'.$request->string('search').'%')->orWhere('tracking_code', 'like', '%'.$request->string('search').'%')));

        return response()->json(['data' => $query->latest('updated_at')->paginate(min($request->integer('per_page', 20), 100)), 'counts' => $counts]);
    }

    public function options(Request $request): JsonResponse
    {
        abort_unless($this->policy->viewAny($request->user()), 403);
        $magazines = Magazine::query()->where('is_active', true)
            ->when(! $request->user()->hasRole('super_admin'), fn ($q) => $q->whereIn('id', $this->publisherMagazineIds($request)))
            ->orderBy('title')->get(['id', 'title', 'publication_type']);
        $issues = MagazineIssue::query()->whereIn('magazine_id', $magazines->pluck('id'))
            ->orderByDesc('issue_year')->orderByDesc('issue_number')->get(['id', 'magazine_id', 'volume_number', 'issue_number', 'special_title', 'status']);

        return response()->json(['data' => ['magazines' => $magazines, 'issues' => $issues,
            'article_types' => DB::table('article_types')->orderBy('name')->get(['id', 'name']),
            'categories' => DB::table('article_categories')->orderBy('name')->get(['id', 'name']),
            'subject_areas' => DB::table('subject_areas')->orderBy('name')->get(['id', 'name']),
            'languages' => DB::table('languages')->orderBy('name')->get(['id', 'name'])]]);
    }

    public function store(DirectPublicationRequest $request): JsonResponse
    {
        abort_unless($this->policy->create($request->user()) && $this->policy->canAccessMagazine($request->user(), $request->integer('magazine_id')), 403);
        $article = $this->service->createDraft($request->user(), $request->validated(), $this->key($request));

        return response()->json(['message' => 'Direct-publication draft created.', 'data' => $this->serialize($article)], 201);
    }

    public function show(Request $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'view');

        return response()->json(['data' => $this->serialize($article), 'readiness' => $this->service->readiness($article)]);
    }

    public function update(DirectPublicationRequest $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'update');
        if (isset($request->validated()['magazine_id'])) {
            abort_unless($this->policy->canAccessMagazine($request->user(), $request->integer('magazine_id')), 403);
        }

        return $this->ok('Direct publication updated.', $this->service->updateDraft($article, $request->user(), $request->validated(), $this->key($request)));
    }

    public function destroy(Request $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'delete');
        $this->service->deleteDraft($article, $request->user());

        return response()->json(['message' => 'Direct-publication draft deleted.']);
    }

    public function attachFile(DirectPublicationRequest $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'uploadFile');
        $file = $this->service->attachFile($article, $request->user(), $request->validated(), $this->key($request));

        return response()->json(['message' => 'File attached.', 'data' => $file], 201);
    }

    public function deleteFile(Request $request, Article $article, ArticleFile $file): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'uploadFile');

        return $this->ok('File deleted.', $this->service->deleteFile($article, $file, $request->user(), $this->key($request)));
    }

    public function readiness(Request $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'view');
        $result = $this->service->readiness($article);

        return response()->json($result + ['message' => $result['ready'] ? 'Article is ready for publication.' : 'Article is not ready for publication.'], $result['ready'] ? 200 : 422);
    }

    public function selectPrimary(DirectPublicationRequest $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'update');

        return $this->ok('Primary PDF selected.', $this->service->selectPrimary($article, $request->user(), $request->integer('article_file_id'), $this->key($request)));
    }

    public function publicAssets(DirectPublicationRequest $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'update');

        return $this->ok('Publication file visibility updated.', $this->service->selectPublicAssets($article, $request->user(), $request->input('publication_file_settings', []), $this->key($request)));
    }

    public function assignIssue(DirectPublicationRequest $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'update');

        return $this->ok('Issue assignment updated.', $this->service->updateDraft($article, $request->user(), $request->validated(), $this->key($request)));
    }

    public function markReady(Request $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'markReady');

        return $this->readinessMutation('Article marked ready.', fn () => $this->service->markReady($article, $request->user(), $this->key($request)));
    }

    public function moveToDraft(Request $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'update');

        return $this->ok('Article returned to draft.', $this->service->moveToDraft($article, $request->user(), $this->key($request)));
    }

    public function schedule(DirectPublicationRequest $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'schedule');

        return $this->readinessMutation('Publication scheduled.', fn () => $this->service->schedule($article, $request->user(), now()->parse($request->string('scheduled_at')), $this->key($request)));
    }

    public function unschedule(Request $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'schedule');

        return $this->ok('Publication schedule removed.', $this->service->unschedule($article, $request->user(), $this->key($request)));
    }

    public function publish(DirectPublicationRequest $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'publish');

        return $this->readinessMutation('Article published.', fn () => $this->service->publish($article, $request->user(), $this->key($request)));
    }

    public function correctMetadata(DirectPublicationRequest $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'update');

        return $this->readinessMutation('Published metadata corrected.', fn () => $this->service->correctPublishedMetadata($article, $request->user(), $request->validated(), $this->key($request)));
    }

    public function unpublish(DirectPublicationRequest $request, Article $article): JsonResponse
    {
        $this->authorizeArticle($request, $article, 'unpublish');

        return $this->ok('Article unpublished.', $this->service->unpublish($article, $request->user(), $request->string('reason'), $this->key($request)));
    }

    private function authorizeArticle(Request $request, Article $article, string $ability): void
    {
        abort_unless($this->policy->{$ability}($request->user(), $article), 403);
    }

    private function publisherMagazineIds(Request $request): array
    {
        return DB::table('magazine_user')->where('user_id', $request->user()->id)->where(fn ($q) => $q->where('role', 'publisher')->orWhereNull('role'))->pluck('magazine_id')->map(fn ($id) => (int) $id)->all();
    }

    private function key(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        abort_if($key === '' || Str::length($key) > 100, 422, 'A valid Idempotency-Key header is required.');

        return $key;
    }

    private function ok(string $message, Article $article): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $this->serialize($article)]);
    }

    private function readinessMutation(string $message, callable $callback): JsonResponse
    {
        try {
            return $this->ok($message, $callback());
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Article is not ready for publication.',
                'code' => 'DIRECT_PUBLICATION_NOT_READY',
                'errors' => $exception->errors(),
            ], 422);
        }
    }

    private function serialize(Article $article): array
    {
        $article->loadMissing(['magazine', 'articleAuthors', 'files', 'publicationSections', 'currentVersion', 'latestPublicationRecord.issue', 'latestPublicationRecord.primaryFile', 'latestPublicationRecord.files.file']);
        $payload = $article->toArray();
        $payload['publication_sections'] = $article->publicationSections->sortBy('sort_order')->values()->map(fn ($section) => [
            'id' => $section->id, 'section_key' => $section->section_key, 'title' => $section->title,
            'content_html' => $section->content_html, 'content_text' => $section->content_text,
            'sort_order' => $section->sort_order, 'media_upload_session_id' => $section->media_upload_session_id,
            'image_url' => $section->media_upload_session_id ? url("/api/articles/publication-sections/{$section->id}/image") : null,
        ])->all();
        $payload['publication_type_label'] = 'Direct Publication';

        return $payload;
    }
}
