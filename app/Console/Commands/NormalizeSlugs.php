<?php

namespace App\Console\Commands;

use App\Services\SlugNormalizationService;
use Illuminate\Console\Command;

class NormalizeSlugs extends Command
{
    protected $signature = 'slugs:normalize {--details : Show every proven legacy slug} {--apply : Apply safe changes and create redirects}';
    protected $description = 'Audit and safely normalize legacy random publication and article slug suffixes';

    public function handle(SlugNormalizationService $normalizer): int
    {
        $audit = $normalizer->audit();
        $this->components->info('Slug normalization '.($this->option('apply') ? '(apply mode)' : '(read-only)'));
        if ($this->option('details')) {
            foreach ($audit['plans'] as $plan) {
                $this->newLine();
                $this->line(ucfirst($plan['publication_type'] ?: $plan['entity_type']));
                $this->table(['ID', 'Publication', 'Current slug', 'Base slug', 'Proposed slug', 'Collision', 'Redirect', 'Action'], [[
                    $plan['id'], $plan['parent_id'] ?: '-', $plan['current_slug'], $plan['base_slug'], $plan['proposed_slug'],
                    $plan['collision'] ? 'Yes' : 'No', 'Yes', $plan['action'],
                ]]);
            }
            foreach ($audit['manual_reviews'] as $plan) {
                $this->newLine();
                $this->line(ucfirst($plan['entity_type']).' '.$plan['id']);
                $this->table(['Current slug', 'Title-derived slug', 'Action'], [[
                    $plan['current_slug'], $plan['base_slug'], $plan['action'],
                ]]);
            }
        }

        $result = ['applied' => [], 'skipped' => []];
        if ($this->option('apply')) {
            $result = $normalizer->apply($audit);
            foreach ($result['applied'] as $plan) $this->components->info("Updated {$plan['entity_type']} {$plan['id']}: {$plan['current_slug']} -> {$plan['proposed_slug']}");
            foreach ($result['skipped'] as $plan) $this->components->warn("Skipped {$plan['entity_type']} {$plan['id']}; it changed or now conflicts.");
        }

        $summary = $audit['summary'];
        $this->table(['Magazines', 'Journals', 'Articles', 'Legacy found', 'Safe', 'Numeric collisions', 'Manual review', 'Redirects', 'Applied', 'Skipped'], [[
            $summary['magazines_inspected'], $summary['journals_inspected'], $summary['articles_inspected'],
            $summary['legacy_random_slugs_found'], $summary['safe_normalizations'], $summary['numeric_collision_resolutions'],
            $summary['manual_review_records'], $summary['redirects_to_create'], count($result['applied']), count($result['skipped']),
        ]]);
        if (!$this->option('apply')) $this->components->warn('No data was changed. Review --details before --apply.');
        return self::SUCCESS;
    }
}
