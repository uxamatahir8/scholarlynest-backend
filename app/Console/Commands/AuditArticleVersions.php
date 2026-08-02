<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\ArticleVersionIntegrityService;
use Illuminate\Console\Command;

class AuditArticleVersions extends Command
{
    protected $signature = 'articles:audit-versions
        {article : Article ID or tracking code}
        {--repair : Repair a deterministic linear chain}
        {--dry-run : Show the repair without writing changes}';

    protected $description = 'Audit and optionally repair one article version chain';

    public function handle(ArticleVersionIntegrityService $service): int
    {
        $identifier = (string) $this->argument('article');
        $article = ctype_digit($identifier)
            ? Article::query()->find((int) $identifier)
            : Article::query()->where('tracking_code', $identifier)->first();
        if (! $article) {
            $this->error("Article {$identifier} was not found.");

            return self::FAILURE;
        }

        $result = $this->option('repair')
            ? $service->repair($article, (bool) $this->option('dry-run'))
            : $service->inspect($article);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (($result['ambiguous'] ?? []) !== []) {
            $this->error('No changes were made because the version chain is ambiguous.');

            return self::FAILURE;
        }
        if (! $this->option('repair') && ! ($result['valid'] ?? false)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
