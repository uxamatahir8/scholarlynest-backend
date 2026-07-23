<?php

namespace App\Http\Controllers;

use App\Models\Advertisement;
use App\Models\AdvertisementEvent;
use App\Models\Article;
use App\Services\AdvertisementPlacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdvertisementEventController extends Controller
{
    public function store(Request $request, Advertisement $advertisement, AdvertisementPlacementService $placements): JsonResponse
    {
        $data = $request->validate([
            'article_id' => ['required', 'integer', 'exists:articles,id'],
            'event_type' => ['required', Rule::in(['impression', 'click'])],
            'placement' => ['required', Rule::in([...Advertisement::PLACEMENTS, 'left_sidebar', 'right_sidebar'])],
            'session_token' => ['required', 'string', 'min:16', 'max:128'],
        ]);
        $article = Article::with('magazine')->findOrFail($data['article_id']);
        $eligible = collect($placements->forArticlePage($article)[$data['placement']] ?? [])->contains(fn ($ad) => (int) $ad['id'] === (int) $advertisement->id);
        abort_unless($eligible, 404);

        AdvertisementEvent::firstOrCreate([
            'advertisement_id' => $advertisement->id,
            'article_id' => $article->id,
            'event_type' => $data['event_type'],
            'session_hash' => hash('sha256', $data['session_token']),
        ], [
            'publication_id' => $article->magazine_id,
            'placement' => $data['placement'],
            'sidebar_side' => $advertisement->sidebar_side,
        ]);

        return response()->json(null, 204);
    }
}
