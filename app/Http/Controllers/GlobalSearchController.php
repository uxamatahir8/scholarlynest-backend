<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class GlobalSearchController extends Controller
{
    protected GlobalSearchService $searchService;

    public function __construct(GlobalSearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * Preview search suggestions (max 5 matches).
     * GET /api/search/preview
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function preview(Request $request): JsonResponse
    {
        $query = $request->query('q', '');
        $type = $request->query('type', 'all');

        $results = $this->searchService->search($query, $type, true);

        return response()->json($results);
    }

    /**
     * Complete systemic results with pagination.
     * GET /api/search/full
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function full(Request $request): JsonResponse
    {
        $query = $request->query('q', '');
        $type = $request->query('type', 'all');
        $perPage = (int) $request->query('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }

        $results = $this->searchService->search($query, $type, false);

        $currentPage = Paginator::resolveCurrentPage() ?: 1;
        $currentItems = $results->slice(($currentPage - 1) * $perPage, $perPage)->values()->all();

        $paginated = new LengthAwarePaginator(
            $currentItems,
            $results->count(),
            $perPage,
            $currentPage,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => $request->query(),
            ]
        );

        return response()->json($paginated);
    }
}
