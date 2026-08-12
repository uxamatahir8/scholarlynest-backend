<?php

namespace App\Services;

use App\Models\Article;
use App\Models\User;

class WorkflowTabManifestService
{
    public function manifest(Article $article, User $viewer): array
    {
        return app(ArticleWorkspaceManifestService::class)->manifest($article, $viewer);
    }
}
