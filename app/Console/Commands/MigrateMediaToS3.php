<?php

namespace App\Console\Commands;

use App\Models\ArticleAsset;
use App\Models\ArticleFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateMediaToS3 extends Command
{
    protected $signature = 'media:migrate-to-s3
        {--dry-run : Inventory files without copying}
        {--execute : Copy eligible legacy files to incoming/legacy and queue validation}
        {--type= : article_files or article_assets}
        {--limit=100 : Maximum records to inspect}
        {--cursor= : Start after this record id}
        {--only-missing : Only records without an S3 storage key}
        {--report= : Optional JSON report path}';

    protected $description = 'Safely inventory or stage legacy local media for the S3 scan pipeline.';

    public function handle(): int
    {
        if (!$this->option('dry-run') && !$this->option('execute')) {
            $this->error('Choose --dry-run or --execute.');
            return self::FAILURE;
        }

        if ($this->option('execute') && !$this->confirm('Stage legacy files to S3 incoming/legacy without deleting local originals?', false)) {
            $this->warn('Cancelled.');
            return self::SUCCESS;
        }

        $types = $this->option('type') ? [$this->option('type')] : ['article_files', 'article_assets'];
        $report = [];

        foreach ($types as $type) {
            $model = match ($type) {
                'article_files' => ArticleFile::class,
                'article_assets' => ArticleAsset::class,
                default => null,
            };

            if (!$model) {
                $this->warn("Unknown migration type: {$type}");
                continue;
            }

            $query = $model::query()
                ->when($this->option('cursor'), fn ($q, $cursor) => $q->where('id', '>', (int) $cursor))
                ->when($this->option('only-missing'), fn ($q) => $q->whereNull('storage_key'))
                ->orderBy('id')
                ->limit((int) $this->option('limit'));

            foreach ($query->get() as $record) {
                $path = str_replace('storage/', '', $record->file_path);
                $exists = ($record->disk ?? 'public') === 'public' && Storage::disk('public')->exists($path);
                $entry = [
                    'type' => $type,
                    'id' => $record->id,
                    'source' => $record->file_path,
                    'exists' => $exists,
                    'action' => 'none',
                ];

                if (!$exists) {
                    $entry['failure'] = 'local_file_missing';
                    $report[] = $entry;
                    continue;
                }

                if ($this->option('execute')) {
                    $legacyKey = 'incoming/legacy/' . $type . '/' . $record->id . '/' . basename($path);
                    Storage::disk(config('media_uploads.disk'))->put($legacyKey, Storage::disk('public')->get($path));
                    $entry['action'] = 'staged';
                    $entry['legacy_key'] = $legacyKey;
                } else {
                    $entry['action'] = 'would_stage';
                }

                $report[] = $entry;
            }
        }

        if ($this->option('report')) {
            file_put_contents($this->option('report'), json_encode($report, JSON_PRETTY_PRINT));
        }

        $this->info('Media migration inventory complete: ' . count($report) . ' record(s).');
        $this->line(json_encode(array_count_values(array_column($report, 'action')), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
