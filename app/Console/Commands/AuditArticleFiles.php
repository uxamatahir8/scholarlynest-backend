<?php

namespace App\Console\Commands;

use App\Services\ArticleFileCleanupService;
use Illuminate\Console\Command;

class AuditArticleFiles extends Command
{
    protected $signature = 'article-files:audit
        {--details : Show each proposed action and reference count}
        {--apply : Apply only actions classified as safe}
        {--article= : Limit the audit to one article ID}
        {--file-ids= : Limit the audit to comma-separated ArticleFile IDs}';

    protected $description = 'Audit and safely clean duplicate or invalid article-file references';

    public function handle(ArticleFileCleanupService $cleanup): int
    {
        $articleId = $this->option('article') ? (int) $this->option('article') : null;
        $fileIds = collect(explode(',', (string) $this->option('file-ids')))
            ->filter()->map(fn ($id) => (int) trim($id))->filter()->unique()->values()->all();
        $audit = $cleanup->audit($articleId, $fileIds);

        $this->components->info('Article file integrity audit'.($this->option('apply') ? ' (apply mode)' : ' (read-only)'));
        foreach ($audit['duplicate_groups'] as $index => $group) {
            $this->newLine();
            $this->line('Duplicate Group #'.($index + 1));
            $this->table(['Article', 'Version', 'Purpose', 'Records', 'Canonical', 'Proposed removal', 'References', 'Storage deletes', 'Action'], [[
                $group['article_id'], $group['version_id'] ?: '-', $group['file_type'], implode(',', $group['record_ids']),
                $group['canonical_id'], implode(',', $group['remove_ids']), $group['references_to_migrate'], 0,
                $group['safe'] ? 'SAFE TO CLEAN' : 'MANUAL REVIEW REQUIRED',
            ]]);
            if ($this->option('details')) $this->line('Reason: '.$group['reason']);
        }
        foreach ($audit['invalid_records'] as $record) {
            $this->newLine();
            $this->line('Invalid ArticleFile #'.$record['file_id']);
            $this->table(['Article', 'Scan status', 'Object exists', 'Action'], [[
                $record['article_id'], $record['scan_status'] ?: '-', $record['object_exists'] ? 'yes' : 'no',
                $record['safe'] ? 'SAFE TO CLEAN' : 'MANUAL REVIEW REQUIRED',
            ]]);
            if ($this->option('details')) $this->line('Reason: '.$record['reason']);
        }
        foreach ($audit['multiple_primary_manuscripts'] as $record) {
            $this->newLine();
            $this->line('multiple_primary_manuscripts: Article Version #'.$record['article_version_id']);
            $this->table(['Article', 'Version label', 'Manuscript IDs', 'Accepted refs', 'Workflow refs', 'Recommended canonical', 'Action'], [[
                $record['article_id'], $record['version_label'], implode(',', $record['manuscript_article_file_ids']),
                implode(',', $record['accepted_file_set_references']) ?: '-', implode(',', $record['workflow_production_references']) ?: '-',
                $record['recommended_canonical_manuscript'] ?: '-', $record['manual_review_required'] ? 'MANUAL REVIEW REQUIRED' : 'SAFE DUPLICATE CLEANUP AVAILABLE',
            ]]);
        }

        $result = ['applied' => [], 'skipped' => []];
        if ($this->option('apply')) {
            $result = $cleanup->apply($audit);
            foreach ($result['applied'] as $action) $this->components->info("Removed ArticleFile {$action['removed_id']}".($action['canonical_id'] ? "; canonical {$action['canonical_id']}" : '').'.');
            foreach ($result['skipped'] as $action) $this->components->warn("Skipped ArticleFile {$action['file_id']}: {$action['reason']}");
        }

        $this->newLine();
        $this->table(['Duplicate groups', 'Invalid records', 'Multiple manuscripts', 'Safe actions', 'Manual review', 'Applied', 'Skipped'], [[
            count($audit['duplicate_groups']), count($audit['invalid_records']), count($audit['multiple_primary_manuscripts']), count($audit['actions']),
            $audit['manual_review_count'], count($result['applied']), count($result['skipped']),
        ]]);
        $this->components->warn('Storage objects are never deleted by this command.');
        if (!$this->option('apply')) $this->components->warn('No data was changed. Back up tables and review --details before --apply.');
        else $this->line('Run php artisan article-files:audit --details to verify the remaining state.');

        return self::SUCCESS;
    }
}
