<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Constants\ArticleStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    /**
     * Scoped search endpoint.
     * GET /api/admin/search
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        $queryStr = trim($request->query('q', ''));

        if (empty($queryStr)) {
            return response()->json([
                'articles' => [],
                'magazines' => [],
                'issues' => []
            ]);
        }

        // 1. SEARCH ARTICLES (SCOPED BY ROLE/PERMISSIONS)
        $articleQuery = Article::with(['magazine', 'user']);

        if (!$user->hasRole('super_admin') && !$user->hasRole('admin')) {
            $articleQuery->where(function ($q) use ($user) {
                // Own submissions
                $q->where('user_id', $user->id);

                // Editor Scope
                if ($user->hasRole('editor') || $user->hasRole('magazine_editor') || $user->hasRole('magazine-editor')) {
                    $editorMagazineIds = DB::table('magazine_user')
                        ->where('user_id', $user->id)
                        ->whereIn('role', ['editor', 'magazine_editor'])
                        ->pluck('magazine_id')
                        ->toArray();
                    $q->orWhereIn('magazine_id', $editorMagazineIds);
                }

                // Publisher Scope
                if ($user->hasRole('publisher')) {
                    $publisherMagazineIds = DB::table('magazine_user')
                        ->where('user_id', $user->id)
                        ->whereIn('role', ['publisher'])
                        ->pluck('magazine_id')
                        ->toArray();
                    $publisherStatuses = array_values(array_unique(array_merge(
                        ArticleStatus::queryValues(ArticleStatus::ACCEPTED),
                        ArticleStatus::queryValues(ArticleStatus::COPY_EDITING),
                        ArticleStatus::queryValues(ArticleStatus::PROOFREADING),
                        ArticleStatus::queryValues(ArticleStatus::READY_FOR_PUBLICATION),
                        ArticleStatus::queryValues(ArticleStatus::PUBLISHED)
                    )));
                    $q->orWhere(function ($subQ) use ($publisherMagazineIds, $publisherStatuses) {
                        $subQ->whereIn('magazine_id', $publisherMagazineIds)
                            ->whereIn('status', $publisherStatuses);
                    });
                }

                // Sub Editor Scope
                if ($user->hasRole('sub_editor')) {
                    $subEditorArticleIds = DB::table('sub_editor_assignments')
                        ->where('sub_editor_id', $user->id)
                        ->pluck('article_id')
                        ->toArray();
                    $q->orWhereIn('id', $subEditorArticleIds);
                }

                // Reviewer Scope
                if ($user->hasRole('reviewer')) {
                    $reviewerArticleIds = DB::table('reviewer_assignments')
                        ->where('reviewer_id', $user->id)
                        ->pluck('article_id')
                        ->toArray();
                    $q->orWhereIn('id', $reviewerArticleIds);
                }

                // Production (Copy Editor, Proofreader) Scope
                if ($user->hasRole('copy_editor') || $user->hasRole('proofreader')) {
                    $productionArticleIds = DB::table('production_assignments')
                        ->where('user_id', $user->id)
                        ->pluck('article_id')
                        ->toArray();
                    $q->orWhereIn('id', $productionArticleIds);
                }
            });
        }

        $articles = $articleQuery->where(function ($q) use ($queryStr) {
            $q->where('title', 'like', "%{$queryStr}%")
              ->orWhere('abstract', 'like', "%{$queryStr}%");
        })
        ->orderBy('created_at', 'desc')
        ->limit(30)
        ->get()
        ->map(function ($article) {
            return [
                'id' => $article->id,
                'title' => $article->title,
                'status' => $article->status,
                'abstract' => $article->abstract,
                'magazine_title' => $article->magazine ? $article->magazine->title : null,
                'author_name' => $article->user ? $article->user->name : null,
                'created_at' => $article->created_at,
            ];
        });

        // 2. SEARCH MAGAZINES (SCOPED)
        $magazineQuery = Magazine::query();

        if (!$user->hasRole('super_admin') && !$user->hasRole('admin')) {
            $assignedMagazineIds = DB::table('magazine_user')
                ->where('user_id', $user->id)
                ->pluck('magazine_id')
                ->toArray();
            $magazineQuery->whereIn('id', $assignedMagazineIds);
        }

        $magazines = $magazineQuery->where(function ($q) use ($queryStr) {
            $q->where('title', 'like', "%{$queryStr}%")
              ->orWhere('description', 'like', "%{$queryStr}%");
        })
        ->orderBy('title')
        ->limit(15)
        ->get()
        ->map(function ($magazine) {
            return [
                'id' => $magazine->id,
                'title' => $magazine->title,
                'slug' => $magazine->slug,
                'description' => strip_tags($magazine->description),
            ];
        });

        // 3. SEARCH ISSUES (SCOPED)
        $issueQuery = MagazineIssue::with('magazine');

        if (!$user->hasRole('super_admin') && !$user->hasRole('admin')) {
            $assignedMagazineIds = DB::table('magazine_user')
                ->where('user_id', $user->id)
                ->pluck('magazine_id')
                ->toArray();
            $issueQuery->whereIn('magazine_id', $assignedMagazineIds);
        }

        $issues = $issueQuery->where(function ($q) use ($queryStr) {
            $q->where('special_title', 'like', "%{$queryStr}%")
              ->orWhere('description', 'like', "%{$queryStr}%")
              ->orWhere('volume_number', 'like', "%{$queryStr}%")
              ->orWhere('issue_number', 'like', "%{$queryStr}%");
        })
        ->orderBy('created_at', 'desc')
        ->limit(15)
        ->get()
        ->map(function ($issue) {
            return [
                'id' => $issue->id,
                'volume_number' => $issue->volume_number,
                'issue_number' => $issue->issue_number,
                'special_title' => $issue->special_title,
                'status' => $issue->status,
                'issue_month' => $issue->issue_month,
                'issue_year' => $issue->issue_year,
                'magazine_title' => $issue->magazine ? $issue->magazine->title : null,
            ];
        });

        return response()->json([
            'articles' => $articles,
            'magazines' => $magazines,
            'issues' => $issues
        ]);
    }
}
