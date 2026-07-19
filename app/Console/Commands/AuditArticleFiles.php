<?php

namespace App\Console\Commands;

use App\Models\ArticleFile;
use App\Models\MediaUploadSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditArticleFiles extends Command
{
    protected $signature = 'article-files:audit';

    protected $description = 'Report duplicate and invalid article-file references without modifying data';

    public function handle(): int
    {
        $this->components->info('Article file integrity audit (read-only)');

        $duplicateSessions = ArticleFile::query()
            ->whereNotNull('media_upload_session_id')
            ->select('media_upload_session_id', DB::raw('COUNT(*) as total'), DB::raw('MIN(id) as first_id'))
            ->groupBy('media_upload_session_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        $this->reportGroups('Duplicate upload-session references', $duplicateSessions, 'media_upload_session_id');

        $duplicateStorage = ArticleFile::query()
            ->whereNotNull('storage_key')
            ->where('storage_key', '!=', '')
            ->select('storage_key', DB::raw('COUNT(*) as total'), DB::raw('MIN(id) as first_id'))
            ->groupBy('storage_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        $this->reportGroups('Shared storage-object references', $duplicateStorage, 'storage_key', false);

        $invalidStates = ArticleFile::query()
            ->where(function ($query) {
                $query->where('scan_status', '!=', 'clean')
                    ->orWhereNull('storage_key')
                    ->orWhere('storage_key', '');
            })
            ->get(['id', 'article_id', 'article_version_id', 'media_upload_session_id', 'scan_status']);
        $this->table(
            ['Invalid/non-ready file ID', 'Article', 'Version', 'Upload session', 'Scan status'],
            $invalidStates->map(fn (ArticleFile $file) => [
                $file->id,
                $file->article_id,
                $file->article_version_id ?: '-',
                $file->media_upload_session_id ?: '-',
                $file->scan_status ?: '-',
            ])->all()
        );

        $missingObjects = [];
        ArticleFile::query()
            ->where('scan_status', 'clean')
            ->whereNotNull('storage_key')
            ->orderBy('id')
            ->chunkById(100, function ($files) use (&$missingObjects) {
                foreach ($files as $file) {
                    try {
                        if (!Storage::disk($file->disk)->exists($file->storage_key)) {
                            $missingObjects[] = [$file->id, $file->article_id, $file->media_upload_session_id ?: '-'];
                        }
                    } catch (\Throwable) {
                        $missingObjects[] = [$file->id, $file->article_id, $file->media_upload_session_id ?: '-'];
                    }
                }
            });
        $this->table(['Missing-object file ID', 'Article', 'Upload session'], $missingObjects);

        $unattachedCleanUploads = MediaUploadSession::query()
            ->where('status', MediaUploadSession::STATUS_CLEAN)
            ->whereIn('purpose', collect(config('media_uploads.purposes'))
                ->filter(fn ($config) => ($config['target'] ?? null) === 'article')
                ->keys())
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('article_files')
                    ->whereColumn('article_files.media_upload_session_id', 'media_upload_sessions.id');
            })
            ->get(['id', 'purpose', 'attachable_id']);
        $this->table(
            ['Unattached clean upload', 'Purpose', 'Article'],
            $unattachedCleanUploads->map(fn ($upload) => [$upload->id, $upload->purpose, $upload->attachable_id ?: '-'])->all()
        );

        $this->components->warn('No data was changed. Review records and back up tables before any cleanup.');

        return self::SUCCESS;
    }

    private function reportGroups(string $title, $groups, string $groupColumn, bool $showIdentifier = true): void
    {
        $rows = $groups->map(function ($group) use ($groupColumn, $showIdentifier) {
            $query = ArticleFile::query();
            $query->where($groupColumn, $group->{$groupColumn});

            return [
                $showIdentifier ? $group->{$groupColumn} : '(redacted)',
                $query->pluck('id')->join(','),
                $group->total,
            ];
        })->all();
        $this->line($title);
        $this->table(['Identifier', 'ArticleFile IDs', 'Count'], $rows);
    }
}
