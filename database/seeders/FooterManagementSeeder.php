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

        // 2. Link Editorial Board and Contact Us to Resources
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

        FooterPage::updateOrCreate(
            ['slug' => 'contact'],
            [
                'footer_category_id' => $resourcesCat->id,
                'title' => 'Contact Us',
                'content' => '<h3>Contact Us</h3><p>Connect with our Editorial Board via our contact form.</p>',
                'is_visible' => true,
                'sort_order' => 1
            ]
        );

        // 3. Link privacy, terms, and manifests to Institutional
        $privacyCms = CmsPage::where('slug', 'privacy')->first();
        FooterPage::updateOrCreate(
            ['slug' => 'privacy'],
            [
                'footer_category_id' => $institutionalCat->id,
                'title' => 'Privacy Statement',
                'content' => $privacyCms ? $privacyCms->content_html : '<h3>Privacy Statement</h3><p>Privacy statement details...</p>',
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
    }
}
