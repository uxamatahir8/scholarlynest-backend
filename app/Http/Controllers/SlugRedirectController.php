<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Magazine;
use App\Models\SlugRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlugRedirectController extends Controller
{
    public function sitemap(): JsonResponse
    {
        $publications = Magazine::query()->where('is_active', true)->orderBy('id')->get()
            ->map(fn (Magazine $publication) => [
                'path' => "/{$publication->publicRoutePrefix()}/{$publication->slug}/about-and-overview",
                'updated_at' => $publication->updated_at,
            ]);
        $articles = Article::query()->where('status', 'published')->with('magazine')->orderBy('id')->get()
            ->map(fn (Article $article) => [
                'path' => "/{$article->magazine->publicRoutePrefix()}/{$article->magazine->slug}/articles/{$article->slug}",
                'updated_at' => $article->updated_at,
            ]);

        return response()->json(['data' => $publications->concat($articles)->values()]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'publication_type' => 'nullable|in:magazine,journal',
            'publication_slug' => 'nullable|string|max:255|required_with:publication_type',
            'article_slug' => 'nullable|string|max:255',
        ]);

        if (!empty($data['publication_slug'])) {
            $publication = Magazine::query()
                ->where('publication_type', $data['publication_type'])
                ->where('slug', $data['publication_slug'])
                ->first();
            if (!$publication) {
                $alias = SlugRedirect::query()->where('entity_type', 'publication')->where('old_slug', $data['publication_slug'])->first();
                $publication = $alias ? Magazine::whereKey($alias->entity_id)->where('publication_type', $data['publication_type'])->first() : null;
            }
            if (!$publication) return response()->json(['message' => 'Not found.'], 404);

            $prefix = $publication->publicRoutePrefix();
            $path = "/{$prefix}/{$publication->slug}";
            if (!empty($data['article_slug'])) {
                $article = Article::where('magazine_id', $publication->id)->where('slug', $data['article_slug'])->first();
                if (!$article) {
                    $alias = SlugRedirect::query()
                        ->where('entity_type', 'article')->where('parent_id', $publication->id)
                        ->where('old_slug', $data['article_slug'])->first();
                    $article = $alias ? Article::whereKey($alias->entity_id)->where('magazine_id', $publication->id)->first() : null;
                }
                if (!$article) return response()->json(['message' => 'Not found.'], 404);
                $path .= "/articles/{$article->slug}";
            }

            $incoming = '/'.($data['publication_type'] === 'journal' ? 'journals' : 'magazines').'/'.$data['publication_slug']
                .(!empty($data['article_slug']) ? '/articles/'.$data['article_slug'] : '');
            return response()->json(['canonical_path' => $path, 'redirect_required' => $incoming !== $path]);
        }

        if (!empty($data['article_slug'])) {
            $article = Article::where('slug', $data['article_slug'])->first();
            if (!$article) {
                $aliases = SlugRedirect::where('entity_type', 'article')->where('old_slug', $data['article_slug'])->get();
                if ($aliases->count() === 1) $article = Article::find($aliases->first()->entity_id);
            }
            if (!$article) return response()->json(['message' => 'Not found.'], 404);
            $article->loadMissing('magazine');
            $path = "/{$article->magazine->publicRoutePrefix()}/{$article->magazine->slug}/articles/{$article->slug}";
            return response()->json(['canonical_path' => $path, 'redirect_required' => true]);
        }

        return response()->json(['message' => 'Not found.'], 404);
    }
}
