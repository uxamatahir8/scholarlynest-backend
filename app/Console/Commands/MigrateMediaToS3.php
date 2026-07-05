<?php

namespace App\Console\Commands;

use App\Models\ArticleAsset;
use App\Models\ArticleFile;
use App\Models\MediaUploadSession;
use App\Models\User;
use App\Jobs\ScanPendingMedia;
use App\Services\Media\S3MediaKeyResolver;
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

    public function handle(S3MediaKeyResolver $keys): int
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
                    $legacyKey = $keys->legacy($type, $record->id, basename($path));
                    Storage::disk(config('media_uploads.disk'))->put($legacyKey, Storage::disk('public')->get($path));
                    $session = $this->createLegacySession($type, $record, $legacyKey, Storage::disk('public')->size($path));
                    if ($session->wasRecentlyCreated || $session->status === MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN) {
                        ScanPendingMedia::dispatch($session->id);
                    }
                    $entry['action'] = 'staged';
                    $entry['legacy_key'] = $legacyKey;
                    $entry['upload_session_id'] = $session->id;
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

    private function createLegacySession(string $type, ArticleFile|ArticleAsset $record, string $legacyKey, int $size): MediaUploadSession
    {
        $article = $record->article;
        $userId = $record instanceof ArticleFile
            ? ($record->uploaded_by ?: $article?->user_id)
            : $article?->user_id;
        $userId ??= User::query()->value('id');

        if (!$userId || !$article) {
            throw new \RuntimeException("legacy_migration_missing_article_or_user:{$type}:{$record->id}");
        }

        $purpose = $this->purposeForRecord($record);
        $originalName = $record instanceof ArticleFile
            ? ($record->safe_original_name ?: $record->original_name ?: basename($record->file_path))
            : ($record->safe_original_filename ?: $record->original_filename ?: basename($record->file_path));

        return MediaUploadSession::firstOrCreate(
            ['s3_incoming_key' => $legacyKey],
            [
                'user_id' => $userId,
                'purpose' => $purpose,
                'attachable_type' => $article::class,
                'attachable_id' => $article->id,
                'original_filename' => $originalName,
                'safe_display_filename' => basename($originalName),
                'expected_size_bytes' => $size,
                'declared_mime_type' => $record->mime_type ?: 'application/octet-stream',
                'disk' => config('media_uploads.disk'),
                'upload_mode' => 'single',
                'status' => MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN,
                'metadata' => $record instanceof ArticleFile
                    ? ['article_file_id' => $record->id, 'legacy_migration' => true]
                    : ['article_asset_id' => $record->id, 'legacy_migration' => true],
                'completed_at' => now(),
                'scan_requested_at' => now(),
                'expires_at' => now()->addYear(),
            ]
        );
    }

    private function purposeForRecord(ArticleFile|ArticleAsset $record): string
    {
        if ($record instanceof ArticleAsset) {
            return 'article_supplementary';
        }

        return match ($record->file_type) {
            ArticleFile::MANUSCRIPT => 'article_manuscript',
            ArticleFile::SUPPLEMENTARY => 'article_supplementary',
            ArticleFile::COPY_EDITED_FILE => 'article_production_file',
            ArticleFile::PROOF_FILE => 'article_proof_file',
            ArticleFile::PUBLICATION_PDF => 'article_published_pdf',
            default => 'article_revision',
        };
    }
}
