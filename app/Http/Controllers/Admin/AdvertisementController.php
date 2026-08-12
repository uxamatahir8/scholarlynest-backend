<?php

namespace App\Http\Controllers\Admin;

use App\Constants\ArticleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdvertisementRequest;
use App\Models\Advertisement;
use App\Models\Article;
use App\Models\CmsPage;
use App\Models\Magazine;
use App\Models\MagazinePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdvertisementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Advertisement::with(['targets.publication:id,title,slug,publication_type', 'targets.article:id,title,slug,magazine_id', 'image:id,storage_key,scan_status', 'creator:id,name']);
        foreach (['status', 'placement'] as $field) if ($request->filled($field)) $query->where($field, $request->input($field));
        if ($request->filled('search')) $query->where('title', 'like', '%'.$request->input('search').'%');
        $paginator = $query->orderByDesc('created_at')->paginate(min($request->integer('per_page', 20), 100));
        $paginator->through(fn (Advertisement $advertisement) => $this->adminPayload($advertisement));
        return response()->json($paginator);
    }

    public function store(AdvertisementRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $request->safe()->except('targets');
            $ad = Advertisement::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $ad->targets()->createMany($request->validated('targets'));
            return response()->json($this->adminPayload($ad), 201);
        });
    }

    public function show(Advertisement $advertisement): JsonResponse { return response()->json($this->adminPayload($advertisement)); }

    public function update(AdvertisementRequest $request, Advertisement $advertisement): JsonResponse
    {
        return DB::transaction(function () use ($request, $advertisement) {
            $advertisement->update($request->safe()->except('targets') + ['updated_by' => $request->user()->id]);
            $advertisement->targets()->delete();
            $advertisement->targets()->createMany($request->validated('targets'));
            return response()->json($this->adminPayload($advertisement));
        });
    }

    public function destroy(Advertisement $advertisement): JsonResponse { $advertisement->delete(); return response()->json(null, 204); }

    public function status(Request $request, Advertisement $advertisement): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Advertisement::STATUSES)]]);
        $advertisement->update($data + ['updated_by' => $request->user()->id]);
        return response()->json($this->adminPayload($advertisement));
    }

    public function staticPages(): JsonResponse
    {
        $pages = collect([['page_key' => 'home', 'label' => 'Home', 'path' => '/'], ['page_key' => 'about', 'label' => 'About', 'path' => '/about'], ['page_key' => 'contact', 'label' => 'Contact', 'path' => '/contact'], ['page_key' => 'faq', 'label' => 'FAQs', 'path' => '/faqs']]);
        CmsPage::query()->select(['slug', 'title'])->get()->each(fn ($page) => $pages->push(['page_key' => $page->slug, 'label' => $page->title, 'path' => '/'.$page->slug]));
        return response()->json(['data' => $pages->unique('page_key')->values()]);
    }

    public function publications(Request $request): JsonResponse
    {
        $type = $request->validate(['publication_type' => ['required', Rule::in(['magazine', 'journal'])]])['publication_type'];
        return response()->json(['data' => Magazine::where('publication_type', $type)->orderBy('title')->get(['id', 'title', 'slug', 'publication_type'])]);
    }

    public function publicationPages(Magazine $publication): JsonResponse
    {
        $standard = collect([['page_key' => 'about-and-overview', 'label' => 'About and overview'], ['page_key' => 'table-of-contents', 'label' => 'Table of contents']]);
        $publication->pages()->where('status', 'published')->get(['slug', 'title'])->each(fn ($p) => $standard->push(['page_key' => $p->slug, 'label' => $p->title]));
        return response()->json(['data' => $standard]);
    }

    public function publishedArticles(Magazine $publication): JsonResponse
    {
        return response()->json(['data' => $publication->articles()
            ->whereIn('status', ArticleStatus::queryValues(ArticleStatus::PUBLISHED))
            ->orderBy('title')->get(['id', 'title', 'slug', 'magazine_id'])]);
    }

    private function adminPayload(Advertisement $advertisement): array
    {
        $advertisement->loadMissing(['targets.publication:id,title,slug,publication_type', 'targets.article:id,title,slug,magazine_id', 'image:id,storage_key,scan_status', 'creator:id,name']);
        $public = $advertisement->publicPayload();
        return [
            'id' => $advertisement->id,
            'title' => $advertisement->title,
            'alt_text' => $advertisement->alt_text,
            'image_media_id' => $advertisement->image_media_id,
            'image_url' => $public['image_url'],
            'redirect_url' => $advertisement->redirect_url,
            'open_in_new_tab' => $advertisement->open_in_new_tab,
            'placement' => $advertisement->placement,
            'sidebar_side' => $advertisement->sidebar_side,
            'status' => $advertisement->status,
            'priority' => $advertisement->priority,
            'starts_at' => $advertisement->starts_at?->toISOString(),
            'ends_at' => $advertisement->ends_at?->toISOString(),
            'created_at' => $advertisement->created_at?->toISOString(),
            'updated_at' => $advertisement->updated_at?->toISOString(),
            'created_by' => $advertisement->creator ? ['id' => $advertisement->creator->id, 'name' => $advertisement->creator->name] : null,
            'targets' => $advertisement->targets->map(fn ($target) => [
                'id' => $target->id,
                'target_area' => $target->target_area,
                'target_mode' => $target->target_mode,
                'publication_type' => $target->publication_type,
                'publication_id' => $target->publication_id,
                'publication_label' => $target->publication_type ? ucfirst($target->publication_type) : null,
                'publication_name' => $target->publication?->title,
                'publication_slug' => $target->publication?->slug,
                'page_key' => $target->page_key,
                'page_label' => $target->page_key ? $this->pageLabel($target->page_key) : null,
                'article_id' => $target->article_id,
                'article_title' => $target->article?->title,
                'article_slug' => $target->article?->slug,
            ])->values()->all(),
        ];
    }

    private function pageLabel(string $key): string
    {
        return match ($key) {
            'home' => 'Home', 'about' => 'About', 'contact' => 'Contact', 'faq' => 'FAQs',
            'about-and-overview' => 'About and Overview', 'table-of-contents' => 'Table of Contents',
            default => str($key)->replace(['-', '_'], ' ')->title()->toString(),
        };
    }
}
