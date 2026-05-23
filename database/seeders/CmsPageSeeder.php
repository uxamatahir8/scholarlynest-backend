<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use Illuminate\Database\Seeder;

class CmsPageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Terms of Service
        CmsPage::updateOrCreate(
            ['slug' => 'terms'],
            [
                'title' => 'Terms of Service',
                'content_text' => "01 Acceptance of Publishing Terms\nBy accessing the ScholarlyNest portal, uploading blog drafts, or participating in the editorial review pipeline, you agree to be bound by these Terms of Service. If you disagree with any part of the terms, you may not access our editorial services.\n\n02 Intellectual Property & Open Access Licensing\nScholarlyNest adheres to strict open-access principles. All finalized blog articles published on the platform are released under the Creative Commons Attribution 4.0 International License (CC BY 4.0).\n\n03 Editorial Standards & Community Guidelines\nWe prioritize academic integrity. Authors are strictly prohibited from submitting plagiarized materials, manipulated datasets, or articles previously published in indexed journals without explicit acknowledgment. Our editorial board reserves the right to reject or retract any content that violates these guidelines.",
                'content_html' => '<section class="space-y-4">
  <h2 class="font-serif text-xl font-bold text-zinc-900 dark:text-white flex items-center">
    <span class="w-6 h-6 rounded-full bg-zinc-100 dark:bg-zinc-900 flex items-center justify-center text-[10px] mr-3 text-zinc-500 font-mono">01</span>
    Acceptance of Publishing Terms
  </h2>
  <div class="pl-9 space-y-4 text-sm leading-relaxed">
    <p>
      By accessing the ScholarlyNest portal, uploading blog drafts, or participating in the editorial review pipeline, you agree to be bound by these Terms of Service. If you disagree with any part of the terms, you may not access our editorial services.
    </p>
  </div>
</section>

<section class="space-y-4 mt-8">
  <h2 class="font-serif text-xl font-bold text-zinc-900 dark:text-white flex items-center">
    <span class="w-6 h-6 rounded-full bg-zinc-100 dark:bg-zinc-900 flex items-center justify-center text-[10px] mr-3 text-zinc-500 font-mono">02</span>
    Intellectual Property & Open Access Licensing
  </h2>
  <div class="pl-9 space-y-4 text-sm leading-relaxed">
    <p>
      ScholarlyNest adheres to strict open-access principles. All finalized blog articles published on the platform are released under the Creative Commons Attribution 4.0 International License (CC BY 4.0).
    </p>
    <p>
      Authors retain original copyright to their submitted text, graphical abstracts, and multimedia. However, by publishing through our portal, you grant ScholarlyNest a non-exclusive, irrevocable license to host, format, globally distribute, and index the content in academic search engines.
    </p>
  </div>
</section>

<section class="space-y-4 mt-8">
  <h2 class="font-serif text-xl font-bold text-zinc-900 dark:text-white flex items-center">
    <span class="w-6 h-6 rounded-full bg-zinc-100 dark:bg-zinc-900 flex items-center justify-center text-[10px] mr-3 text-zinc-500 font-mono">03</span>
    Editorial Standards & Community Guidelines
  </h2>
  <div class="pl-9 space-y-4 text-sm leading-relaxed">
    <p>
      We prioritize academic integrity. Authors are strictly prohibited from submitting plagiarized materials, manipulated datasets, or articles previously published in indexed journals without explicit acknowledgment. Our editorial board reserves the right to reject or retract any content that violates these guidelines.
    </p>
    <p>
      All users allocated through our permissions system must participate without abusive behaviors or spam. Commenters and writers agree to maintain clean, constructive dialogues. Exploiting platform resources for unsolicited advertisements is strictly prohibited.
    </p>
  </div>
</section>',
                'is_active' => true,
            ]
        );

        // 2. Privacy Statement
        CmsPage::updateOrCreate(
            ['slug' => 'privacy'],
            [
                'title' => 'Privacy Policy',
                'content_text' => "1. Information Collection\nWhen registering as an author, editor, or administrator, we collect standard identity records including your name, institutional email address, and academic credentials.\n\n2. Secure Storage Protocols\nWe implement enterprise security standards. User authentication is secured via modern encrypted hashing algorithms.\n\n3. Transparency & Third-Party Indexing\nTo fulfill our mission of Open Access science, published blogs and their associated metadata are intentionally indexed by academic search engines (e.g., Google Scholar) globally.",
                'content_html' => '<section class="bg-white dark:bg-[#181817] border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 sm:p-8 shadow-sm">
  <h2 class="font-serif text-xl font-bold text-zinc-900 dark:text-white mb-4 flex items-center">
    1. Information Collection
  </h2>
  <div class="space-y-4 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
    <p>
      When registering as an author, editor, or administrator, we collect standard identity records including your name, institutional email address, and academic credentials.
    </p>
    <p>
      During manuscript submission, draft contents, reference assets, and taxonomy metadata are temporarily cached before final publication. To protect academic integrity during peer review, author identities are decoupled from manuscripts in our editorial dashboard interfaces.
    </p>
  </div>
</section>

<section class="bg-white dark:bg-[#181817] border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 sm:p-8 shadow-sm mt-8">
  <h2 class="font-serif text-xl font-bold text-zinc-900 dark:text-white mb-4 flex items-center">
    2. Secure Storage Protocols
  </h2>
  <div class="space-y-4 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
    <p>
      We implement enterprise security standards. User authentication is secured via modern encrypted hashing algorithms. Multimedia attachments and scientific figures are isolated within secure cloud environments to prevent unauthorized data scraping.
    </p>
  </div>
</section>

<section class="bg-white dark:bg-[#181817] border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 sm:p-8 shadow-sm mt-8">
  <h2 class="font-serif text-xl font-bold text-zinc-900 dark:text-white mb-4 flex items-center">
    3. Transparency & Third-Party Indexing
  </h2>
  <div class="space-y-4 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
    <p>
      To fulfill our mission of Open Access science, published blogs and their associated metadata are intentionally indexed by academic search engines (e.g., Google Scholar) globally. By publishing on ScholarlyNest, you consent to this public distribution.
    </p>
  </div>
</section>',
                'is_active' => true,
            ]
        );

        // 3. Metadata Manifests (Compliance block description)
        CmsPage::updateOrCreate(
            ['slug' => 'manifests'],
            [
                'title' => 'Metadata Manifests Guidelines',
                'content_text' => "Open Archives Initiative (OAI-PMH) Compliance\nScholarlyNest implements full semantic schema standards to ensure scientific blogs published on the platform are immediately indexable. Our headless engine automatically parses Tippy WYSIWYG outputs, attaches references, and injects structural headers dynamically.",
                'content_html' => '<h3 class="font-serif text-sm font-bold text-zinc-900 dark:text-white mb-3">
  Open Archives Initiative (OAI-PMH) Compliance
</h3>
<p class="text-xs text-zinc-550 dark:text-zinc-400 leading-relaxed">
  ScholarlyNest implements full semantic schema standards to ensure scientific blogs published on the platform are immediately indexable. Our headless engine automatically parses Tippy WYSIWYG outputs, attaches references, and injects structural headers dynamically.
</p>',
                'is_active' => true,
            ]
        );
    }
}
