<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Support\Facades\DB;

class ArticleVersionIntegrityService
{
    public function inspect(Article $article): array
    {
        $versions = $article->versions()->orderBy('version_number')->orderBy('id')->get();
        $issues = [];
        $add = function (string $code, string $message, array $versionIds = []) use (&$issues): void {
            $issues[] = compact('code', 'message') + ['version_ids' => $versionIds];
        };

        $initials = $versions->filter(fn ($version) => $version->revision_number !== null && (int) $version->revision_number === 0);
        if ($initials->count() !== 1) {
            $add($initials->isEmpty() ? 'missing_initial' : 'multiple_initial_versions',
                $initials->isEmpty() ? 'No version has persisted revision_number 0.' : 'More than one version has persisted revision_number 0.',
                $initials->pluck('id')->all());
        }

        $positive = $versions->filter(fn ($version) => (int) $version->revision_number > 0);
        $duplicates = $positive->groupBy(fn ($version) => (int) $version->revision_number)
            ->filter(fn ($rows) => $rows->count() > 1);
        foreach ($duplicates as $revision => $rows) {
            $add('duplicate_revision_number', "Revision R{$revision} occurs more than once.", $rows->pluck('id')->all());
        }

        $revisionNumbers = $positive->pluck('revision_number')->map(fn ($number) => (int) $number)->unique()->sort()->values();
        if ($versions->count() > 1 && ! $revisionNumbers->contains(1)) {
            $add('missing_r1', 'The version chain has revisions but no persisted R1.');
        }
        if ($revisionNumbers->isNotEmpty()) {
            $missing = collect(range(1, $revisionNumbers->max()))->diff($revisionNumbers)->values();
            if ($missing->isNotEmpty()) {
                $add('revision_gap', 'Missing revision numbers: '.$missing->map(fn ($number) => 'R'.$number)->join(', ').'.');
            }
        }
        foreach ($positive as $version) {
            $expectedTrackingCode = $article->tracking_code.'-R'.(int) $version->revision_number;
            if ($version->revision_tracking_code !== $expectedTrackingCode) {
                $add('tab_label_version_id_mismatch',
                    "Version {$version->id} does not carry the tracking identity expected for R{$version->revision_number}.",
                    [$version->id]);
            }
        }

        $acceptedIds = $versions->filter(fn ($version) => (int) $version->accepted_marker === 1 || $version->accepted_at !== null)
            ->pluck('id')->push($article->accepted_version_id)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($acceptedIds->count() > 1) {
            $add('multiple_accepted_versions', 'More than one version is marked or referenced as accepted.', $acceptedIds->all());
        }

        foreach ($versions as $index => $version) {
            $expectedParent = $index === 0 ? null : (int) $versions[$index - 1]->id;
            $actualParent = $version->parent_version_id === null ? null : (int) $version->parent_version_id;
            if ($actualParent !== $expectedParent) {
                $add('parent_version_mismatch', "Version {$version->id} does not point to the preceding persisted version.", [$version->id]);
            }
        }

        return [
            'article_id' => $article->id,
            'tracking_code' => $article->tracking_code,
            'valid' => $issues === [],
            'issues' => $issues,
            'versions' => $versions->map(fn ($version) => $this->versionRow($article, $version))->all(),
        ];
    }

    public function repair(Article $article, bool $dryRun = true): array
    {
        return DB::transaction(function () use ($article, $dryRun): array {
            $lockedArticle = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            $versions = $lockedArticle->versions()->orderBy('version_number')->orderBy('id')->lockForUpdate()->get();
            $before = $this->inspect($lockedArticle);
            $ambiguous = $this->ambiguities($versions, $before);

            if ($ambiguous !== []) {
                return compact('dryRun', 'ambiguous', 'before') + ['changes' => [], 'after' => null];
            }

            $changes = $versions->values()->map(function ($version, int $index) use ($lockedArticle): array {
                $trackingCode = $index === 0 ? null : $lockedArticle->tracking_code.'-R'.$index;

                return [
                    'version_id' => $version->id,
                    'from_revision_number' => $version->revision_number,
                    'to_revision_number' => $index,
                    'from_revision_tracking_code' => $version->revision_tracking_code,
                    'to_revision_tracking_code' => $trackingCode,
                ];
            })->filter(fn ($change) => $change['from_revision_number'] === null
                || (int) $change['from_revision_number'] !== $change['to_revision_number']
                || $change['from_revision_tracking_code'] !== $change['to_revision_tracking_code'])->values()->all();

            if (! $dryRun) {
                $lockedArticle->versions()->whereIn('id', collect($changes)->pluck('version_id'))->update(['revision_tracking_code' => null]);
                foreach ($changes as $change) {
                    $values = [
                        'revision_number' => $change['to_revision_number'],
                        'revision_tracking_code' => $change['to_revision_tracking_code'],
                    ];
                    $lockedArticle->versions()->whereKey($change['version_id'])->update($values);
                }
                DB::table('article_audit_logs')->insert([
                    'article_id' => $lockedArticle->id,
                    'actor_id' => null,
                    'event' => 'article.version_integrity_repaired',
                    'from_status' => $lockedArticle->status,
                    'to_status' => $lockedArticle->status,
                    'payload' => json_encode(['changes' => $changes]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $lockedArticle->unsetRelation('versions');
            $after = $dryRun ? null : $this->inspect($lockedArticle);

            return compact('dryRun', 'before', 'changes', 'after') + ['ambiguous' => []];
        }, 3);
    }

    private function ambiguities($versions, array $inspection): array
    {
        $ambiguous = [];
        if ($versions->isEmpty()) {
            return ['The article has no version records.'];
        }
        if ($versions->pluck('version_number')->map(fn ($number) => (int) $number)->values()->all() !== range(1, $versions->count())) {
            $ambiguous[] = 'Version numbers are not a complete sequence beginning at 1.';
        }
        if ($versions->first()->parent_version_id !== null
            || $versions->skip(1)->values()->contains(fn ($version, $index) => (int) $version->parent_version_id !== (int) $versions[$index]->id)) {
            $ambiguous[] = 'Parent relationships do not form one linear version chain.';
        }
        if (collect($inspection['issues'])->contains('code', 'multiple_accepted_versions')) {
            $ambiguous[] = 'Accepted-version ownership is ambiguous.';
        }
        if ($versions->skip(1)->contains(fn ($version) => $version->revision_number === null && $version->label !== 'Revised Manuscript')) {
            $ambiguous[] = 'A non-initial row without a revision number is not identified as a revised manuscript.';
        }

        return $ambiguous;
    }

    private function versionRow(Article $article, $version): array
    {
        $isInitial = ($version->revision_number !== null && (int) $version->revision_number === 0)
            || ($version->revision_number === null && (int) $version->version_number === 1 && $version->parent_version_id === null);
        $accepted = (int) $article->accepted_version_id === (int) $version->id
            || (int) $version->accepted_marker === 1 || $version->accepted_at !== null;
        $label = $isInitial ? 'Initial Submission ('.$article->tracking_code.')'
            : $article->tracking_code.' – R'.($version->revision_number ?? '?');

        return [
            'article_id' => $article->id,
            'version_id' => $version->id,
            'version_number' => (int) $version->version_number,
            'revision_number' => is_null($version->revision_number) ? null : (int) $version->revision_number,
            'parent_version_id' => $version->parent_version_id,
            'submitted_at' => $version->submitted_at?->toISOString(),
            'is_current' => (int) $article->current_version_id === (int) $version->id,
            'is_accepted' => $accepted,
            'expected_tab_key' => 'version-'.$version->id,
            'expected_tab_label' => $label.($accepted ? ' (Accepted)' : ''),
        ];
    }
}
