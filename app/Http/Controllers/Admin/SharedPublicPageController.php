<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Magazine;
use App\Models\SharedPublicPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SharedPublicPageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $pages = SharedPublicPage::with(['creator:id,name', 'targets.publication:id,title,slug,publication_type'])
            ->orderBy('sort_order')->orderBy('title')
            ->paginate($request->integer('per_page', 20));

        $pages->through(fn (SharedPublicPage $page) => $this->payload($page));

        return response()->json($pages);
    }

    public function show(Request $request, SharedPublicPage $sharedPage): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json($this->payload($sharedPage->load(['creator:id,name', 'targets.publication:id,title,slug,publication_type'])));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $validated = $this->validated($request);
        $page = DB::transaction(function () use ($request, $validated) {
            $page = SharedPublicPage::create(array_merge($this->pageData($validated), [
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]));
            $this->syncTargets($page, $validated);

            return $page;
        });

        return response()->json(['message' => 'Shared page created successfully.', 'page' => $this->payload($page->load(['creator:id,name', 'targets.publication:id,title,slug,publication_type']))], 201);
    }

    public function update(Request $request, SharedPublicPage $sharedPage): JsonResponse
    {
        $this->authorizeAdmin($request);
        $validated = $this->validated($request, $sharedPage);
        DB::transaction(function () use ($request, $validated, $sharedPage) {
            $sharedPage->update(array_merge($this->pageData($validated), ['updated_by' => $request->user()->id]));
            $this->syncTargets($sharedPage, $validated);
        });

        return response()->json(['message' => 'Shared page updated successfully.', 'page' => $this->payload($sharedPage->load(['creator:id,name', 'targets.publication:id,title,slug,publication_type']))]);
    }

    public function destroy(Request $request, SharedPublicPage $sharedPage): JsonResponse
    {
        $this->authorizeAdmin($request);
        $sharedPage->delete();

        return response()->json(['message' => 'Shared page deleted successfully.']);
    }

    public function status(Request $request, SharedPublicPage $sharedPage): JsonResponse
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'draft', 'private', 'inactive'])]]);
        $sharedPage->update(['status' => $validated['status'], 'updated_by' => $request->user()->id]);

        return response()->json(['message' => 'Shared page status updated.', 'page' => $this->payload($sharedPage->load(['creator:id,name', 'targets.publication:id,title,slug,publication_type']))]);
    }

    public function publications(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate(['publication_type' => ['required', Rule::in([Magazine::TYPE_MAGAZINE, Magazine::TYPE_JOURNAL])]]);

        return response()->json(Magazine::query()->where('publication_type', $validated['publication_type'])
            ->orderBy('title')->get(['id', 'title', 'slug', 'publication_type']));
    }

    private function validated(Request $request, ?SharedPublicPage $page = null): array
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('shared_public_pages', 'slug')->ignore($page?->id)],
            'content' => 'required|string',
            'status' => ['required', Rule::in(['active', 'draft', 'private', 'inactive'])],
            'target_scope' => ['required', Rule::in(SharedPublicPage::SCOPES)],
            'show_in_navigation' => 'required|boolean',
            'sort_order' => 'required|integer|min:0',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'selected_magazine_ids' => 'sometimes|array',
            'selected_magazine_ids.*' => 'integer|distinct|exists:magazines,id',
            'selected_journal_ids' => 'sometimes|array',
            'selected_journal_ids.*' => 'integer|distinct|exists:magazines,id',
        ]);

        $validated['slug'] = Str::slug($validated['slug'] ?: $validated['title']);
        if ($validated['slug'] === '' || in_array($validated['slug'], $this->reservedSlugs(), true)) {
            throw ValidationException::withMessages(['slug' => ['Choose a URL-safe slug that does not conflict with a standard publication route.']]);
        }
        if (SharedPublicPage::where('slug', $validated['slug'])->when($page, fn ($query) => $query->whereKeyNot($page->id))->exists()) {
            throw ValidationException::withMessages(['slug' => ['The slug has already been taken.']]);
        }

        $magazineIds = array_values(array_unique($validated['selected_magazine_ids'] ?? []));
        $journalIds = array_values(array_unique($validated['selected_journal_ids'] ?? []));
        if ($magazineIds && Magazine::whereIn('id', $magazineIds)->where('publication_type', '!=', Magazine::TYPE_MAGAZINE)->exists()) {
            throw ValidationException::withMessages(['selected_magazine_ids' => ['Magazine targets may only contain Magazines.']]);
        }
        if ($journalIds && Magazine::whereIn('id', $journalIds)->where('publication_type', '!=', Magazine::TYPE_JOURNAL)->exists()) {
            throw ValidationException::withMessages(['selected_journal_ids' => ['Journal targets may only contain Journals.']]);
        }

        $scope = $validated['target_scope'];
        if ($scope === SharedPublicPage::SCOPE_SELECTED_MAGAZINES && $magazineIds === []) {
            throw ValidationException::withMessages(['selected_magazine_ids' => ['Select at least one Magazine.']]);
        }
        if ($scope === SharedPublicPage::SCOPE_SELECTED_JOURNALS && $journalIds === []) {
            throw ValidationException::withMessages(['selected_journal_ids' => ['Select at least one Journal.']]);
        }
        if ($scope === SharedPublicPage::SCOPE_CUSTOM && $magazineIds === [] && $journalIds === []) {
            throw ValidationException::withMessages(['selected_magazine_ids' => ['Select at least one Magazine or Journal.']]);
        }

        $validated['selected_magazine_ids'] = $magazineIds;
        $validated['selected_journal_ids'] = $journalIds;

        return $validated;
    }

    private function pageData(array $validated): array
    {
        return collect($validated)->only(['title', 'slug', 'content', 'status', 'target_scope', 'show_in_navigation', 'sort_order', 'seo_title', 'seo_description'])->all();
    }

    private function syncTargets(SharedPublicPage $page, array $validated): void
    {
        $page->targets()->delete();
        $scope = $validated['target_scope'];
        if (! in_array($scope, [SharedPublicPage::SCOPE_SELECTED_MAGAZINES, SharedPublicPage::SCOPE_SELECTED_JOURNALS, SharedPublicPage::SCOPE_CUSTOM], true)) {
            return;
        }

        $rows = [];
        if (in_array($scope, [SharedPublicPage::SCOPE_SELECTED_MAGAZINES, SharedPublicPage::SCOPE_CUSTOM], true)) {
            foreach ($validated['selected_magazine_ids'] as $id) {
                $rows[] = ['publication_id' => $id, 'publication_type' => Magazine::TYPE_MAGAZINE];
            }
        }
        if (in_array($scope, [SharedPublicPage::SCOPE_SELECTED_JOURNALS, SharedPublicPage::SCOPE_CUSTOM], true)) {
            foreach ($validated['selected_journal_ids'] as $id) {
                $rows[] = ['publication_id' => $id, 'publication_type' => Magazine::TYPE_JOURNAL];
            }
        }
        $page->targets()->createMany($rows);
    }

    private function payload(SharedPublicPage $page): array
    {
        $targets = $page->targets->map(fn ($target) => [
            'id' => $target->publication_id,
            'title' => $target->publication?->title,
            'slug' => $target->publication?->slug,
            'publication_type' => $target->publication_type,
        ])->values();

        return [
            'id' => $page->id, 'title' => $page->title, 'slug' => $page->slug, 'content' => $page->content,
            'status' => $page->status, 'target_scope' => $page->target_scope, 'show_in_navigation' => $page->show_in_navigation,
            'sort_order' => $page->sort_order, 'seo_title' => $page->seo_title, 'seo_description' => $page->seo_description,
            'targets' => $targets, 'created_by' => $page->creator ? ['id' => $page->creator->id, 'name' => $page->creator->name] : null,
            'updated_at' => $page->updated_at,
        ];
    }

    private function reservedSlugs(): array
    {
        return ['about-and-overview', 'table-of-contents', 'articles', 'issues', 'pages', 'latest-articles', 'latest-published-articles'];
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole(['super_admin', 'admin']), 403, 'Administrator access required.');
    }
}
