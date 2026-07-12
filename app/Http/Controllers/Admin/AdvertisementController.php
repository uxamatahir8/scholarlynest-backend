<?php

namespace App\Http\Controllers\Admin;

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
        $query = Advertisement::with(['targets', 'image:id,storage_key,scan_status', 'creator:id,name']);
        foreach (['status', 'placement'] as $field) if ($request->filled($field)) $query->where($field, $request->input($field));
        if ($request->filled('search')) $query->where('title', 'like', '%'.$request->input('search').'%');
        return response()->json($query->orderByDesc('created_at')->paginate(min($request->integer('per_page', 20), 100)));
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
        return response()->json(['data' => $publication->articles()->where('status', 'published')->orderBy('title')->get(['id', 'title', 'slug', 'magazine_id'])]);
    }

    private function adminPayload(Advertisement $advertisement): Advertisement
    {
        return $advertisement->fresh()->load(['targets', 'image:id,storage_key,scan_status', 'creator:id,name']);
    }
}
