<?php

namespace App\Console\Commands;

use App\Models\ArticleAsset;
use App\Models\ArticleFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeLegacyLocalMedia extends Command
{
    protected $signature = 'media:purge-legacy-local {--verified-before= : Only purge records scanned before YYYY-MM-DD}';

    protected $description = 'Explicitly remove verified legacy local media after S3 migration retention has elapsed.';

    public function handle(): int
    {
        if (!$this->option('verified-before')) {
            $this->error('--verified-before=YYYY-MM-DD is required.');
            return self::FAILURE;
        }

        $cutoff = now()->parse($this->option('verified-before'))->endOfDay();
        if (!$this->confirm("Delete local originals verified before {$cutoff->toDateString()}? This does not touch S3.", false)) {
            $this->warn('Cancelled.');
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ([ArticleFile::class, ArticleAsset::class] as $model) {
            $model::query()
                ->where('disk', '!=', 'public')
                ->where('scan_status', 'clean')
                ->whereNotNull('storage_key')
                ->where('scanned_at', '<=', $cutoff)
                ->chunkById(100, function ($records) use (&$deleted) {
                    foreach ($records as $record) {
                        $legacyPath = str_replace('storage/', '', $record->getOriginal('file_path'));
                        if ($legacyPath && Storage::disk('public')->exists($legacyPath)) {
                            Storage::disk('public')->delete($legacyPath);
                            $deleted++;
                        }
                    }
                });
        }

        $this->info("Deleted {$deleted} verified local legacy file(s).");

        return self::SUCCESS;
    }
}
