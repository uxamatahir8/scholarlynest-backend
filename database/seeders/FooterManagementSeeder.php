<?php

namespace Database\Seeders;

use App\Models\FooterCategory;
use App\Models\FooterPage;
use App\Models\CmsPage;
use Illuminate\Database\Seeder;

class FooterManagementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create baseline categories
        $resourcesCat = FooterCategory::updateOrCreate(
            ['name' => 'Resources'],
            ['sort_order' => 0]
        );

        $institutionalCat = FooterCategory::updateOrCreate(
            ['name' => 'Institutional'],
            ['sort_order' => 1]
        );

        $quickLinksCat = FooterCategory::updateOrCreate(
            ['name' => 'Quick Links'],
            ['sort_order' => 2]
        );

        // 2. Link Editorial Board to Resources
        $editorialCms = CmsPage::where('slug', 'editorial-board')->first();
        FooterPage::updateOrCreate(
            ['slug' => 'editorial-board'],
            [
                'footer_category_id' => $resourcesCat->id,
                'title' => 'Editorial Board',
                'content' => $editorialCms ? $editorialCms->content_html : '<h3>Editorial Board</h3><p>Board members details...</p>',
                'is_visible' => true,
                'sort_order' => 0
            ]
        );

        // 3. Link privacy, terms, and manifests to Institutional
        $privacyCms = CmsPage::where('slug', 'privacy')->first();
        FooterPage::updateOrCreate(
            ['slug' => 'privacy'],
            [
                'footer_category_id' => $institutionalCat->id,
                'title' => 'Privacy Policy',
                'content' => $privacyCms ? $privacyCms->content_html : '<h3>Privacy Policy</h3><p>Privacy statement details...</p>',
                'is_visible' => true,
                'sort_order' => 0
            ]
        );

        $termsCms = CmsPage::where('slug', 'terms')->first();
        FooterPage::updateOrCreate(
            ['slug' => 'terms'],
            [
                'footer_category_id' => $institutionalCat->id,
                'title' => 'Terms of Service',
                'content' => $termsCms ? $termsCms->content_html : '<h3>Terms of Service</h3><p>Terms of service details...</p>',
                'is_visible' => true,
                'sort_order' => 1
            ]
        );

        $manifestsCms = CmsPage::where('slug', 'manifests')->first();
        FooterPage::updateOrCreate(
            ['slug' => 'manifests'],
            [
                'footer_category_id' => $institutionalCat->id,
                'title' => 'Metadata Manifests',
                'content' => $manifestsCms ? $manifestsCms->content_html : '<h3>Metadata Manifests Guidelines</h3><p>Metadata compliance guidelines...</p>',
                'is_visible' => true,
                'sort_order' => 2
            ]
        );

        // 4. Populate a placeholder/example inside Quick Links if empty
        FooterPage::updateOrCreate(
            ['slug' => 'about-us'],
            [
                'footer_category_id' => $quickLinksCat->id,
                'title' => 'About ScholarlyNest',
                'content' => '<section class="space-y-4"><h3>About ScholarlyNest</h3><p>ScholarlyNest is a premier academic blogging and open research exchange platform designed for researchers, peer reviewers, and enthusiasts.</p></section>',
                'is_visible' => true,
                'sort_order' => 0
            ]
        );
    }
}
