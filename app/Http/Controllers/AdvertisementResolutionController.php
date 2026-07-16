<?php

namespace App\Http\Controllers;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
use App\Services\AdvertisementPlacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdvertisementResolutionController extends Controller
{
    public function __invoke(Request $request, AdvertisementPlacementService $service): JsonResponse
    {
        $data = $request->validate([
            'context' => ['required', Rule::in(['website', 'publication', 'article'])], 'page_key' => ['nullable', 'string', 'max:255'],
            'publication_type' => ['nullable', Rule::in(['magazine', 'journal'])], 'publication_slug' => ['nullable', 'string'], 'article_slug' => ['nullable', 'string'],
        ]);
        if ($data['context'] === 'website') $ads = $service->forWebsitePage($data['page_key'] ?? 'home');
        elseif ($data['context'] === 'publication') {
            $publication = Magazine::where('slug', $data['publication_slug'] ?? '')->where('publication_type', $data['publication_type'] ?? '')->firstOrFail();
            $ads = $service->forPublicationPage($publication, $data['page_key'] ?? 'about-and-overview');
        } else {
            $article = Article::where('slug', $data['article_slug'] ?? '')
                ->whereIn('status', ArticleStatus::queryValues(ArticleStatus::PUBLISHED))
                ->whereHas('magazine', fn ($q) => $q->where('slug', $data['publication_slug'] ?? '')->where('publication_type', $data['publication_type'] ?? ''))
                ->firstOrFail();
            $ads = $service->forArticlePage($article);
        }
        return response()->json(['advertisements' => $ads]);
    }
}
