<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use Illuminate\Database\Seeder;

class EditorialBoardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $html = <<<'HTML'
<div class="space-y-12">
  <!-- Header / Intro -->
  <div class="border-b border-zinc-200 dark:border-zinc-800 pb-8">
    <h1 class="text-3xl font-serif font-bold text-zinc-950 dark:text-zinc-50">Editorial Board</h1>
    <p class="mt-3 text-base text-zinc-650 dark:text-zinc-400 leading-relaxed max-w-3xl">
      ScholarlyNest is guided by a distinguished international board of scholars, researchers, and editors who ensure the highest standards of scientific accuracy, academic rigor, and open-access publication integrity.
    </p>
  </div>

  <!-- Editor-in-Chief Section -->
  <div>
    <h2 class="text-xl font-serif font-semibold text-zinc-900 dark:text-zinc-100 mb-6 flex items-center gap-2">
      <span class="w-1.5 h-6 bg-zinc-900 dark:bg-zinc-100 rounded-full"></span>
      Editor-in-Chief
    </h2>
    <div class="bg-zinc-50 dark:bg-[#1c1c1b] border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 md:p-8 flex flex-col md:flex-row gap-6 items-start animate-in fade-in duration-300">
      <div class="w-16 h-16 rounded-full bg-zinc-900 text-zinc-50 dark:bg-zinc-550 dark:text-zinc-900 flex items-center justify-center font-serif text-2xl font-bold shrink-0">
        ER
      </div>
      <div class="space-y-3">
        <div>
          <h3 class="text-lg font-bold text-zinc-900 dark:text-zinc-50">Dr. Evelyn Reed</h3>
          <p class="text-sm font-medium text-zinc-550 dark:text-zinc-400">Professor of Computational Neuroscience & Cognitive Systems</p>
          <p class="text-xs text-zinc-400 dark:text-zinc-500 font-medium">Massachusetts Institute of Technology (MIT)</p>
        </div>
        <p class="text-sm text-zinc-600 dark:text-zinc-350 leading-relaxed">
          Dr. Reed directs the Neural Interfaces Laboratory. Her research focuses on brain-computer interfaces, neural decoding algorithms, and open-source scientific publishing architectures. She serves as the presiding Editor-in-Chief of ScholarlyNest.
        </p>
        <div class="pt-2">
          <a href="mailto:evelyn.reed@scholarlynest.com" class="inline-flex items-center text-xs font-semibold text-zinc-900 dark:text-zinc-100 hover:underline">
            evelyn.reed@scholarlynest.com
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Associate Editors Grid -->
  <div>
    <h2 class="text-xl font-serif font-semibold text-zinc-900 dark:text-zinc-100 mb-6 flex items-center gap-2">
      <span class="w-1.5 h-6 bg-zinc-900 dark:bg-zinc-100 rounded-full"></span>
      Associate Editors
    </h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 animate-in fade-in duration-300">
      <div class="border border-zinc-200 dark:border-zinc-800 rounded-xl p-5 space-y-2 bg-white/50 dark:bg-zinc-900/30">
        <h3 class="text-base font-bold text-zinc-900 dark:text-zinc-50">Dr. Alistair Vance</h3>
        <p class="text-xs font-semibold text-zinc-550 dark:text-zinc-400">Quantum Computing & Cryptographic Systems</p>
        <p class="text-xs text-zinc-400 dark:text-zinc-500 font-medium">Oxford University, UK</p>
      </div>
      <div class="border border-zinc-200 dark:border-zinc-800 rounded-xl p-5 space-y-2 bg-white/50 dark:bg-zinc-900/30">
        <h3 class="text-base font-bold text-zinc-900 dark:text-zinc-50">Dr. Sofia Rodriguez</h3>
        <p class="text-xs font-semibold text-zinc-550 dark:text-zinc-400">Bioinformatics & Genomic Sequencing</p>
        <p class="text-xs text-zinc-400 dark:text-zinc-500 font-medium">Stanford University, USA</p>
      </div>
      <div class="border border-zinc-200 dark:border-zinc-800 rounded-xl p-5 space-y-2 bg-white/50 dark:bg-zinc-900/30">
        <h3 class="text-base font-bold text-zinc-900 dark:text-zinc-50">Dr. Kenji Sato</h3>
        <p class="text-xs font-semibold text-zinc-550 dark:text-zinc-400">Artificial Intelligence & Deep Learning Theory</p>
        <p class="text-xs text-zinc-400 dark:text-zinc-500 font-medium">Tokyo Institute of Technology, Japan</p>
      </div>
      <div class="border border-zinc-200 dark:border-zinc-800 rounded-xl p-5 space-y-2 bg-white/50 dark:bg-zinc-900/30">
        <h3 class="text-base font-bold text-zinc-900 dark:text-zinc-50">Dr. Elena Rostova</h3>
        <p class="text-xs font-semibold text-zinc-550 dark:text-zinc-400">Material Science & Nano-structures</p>
        <p class="text-xs text-zinc-400 dark:text-zinc-500 font-medium">Max Planck Institute, Germany</p>
      </div>
    </div>
  </div>

  <!-- Reviewers Board / Contact Info -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-8 border-t border-zinc-200 dark:border-zinc-800 pt-8">
    <div>
      <h3 class="text-base font-bold text-zinc-900 dark:text-zinc-100 mb-3">Review & Ethics Council</h3>
      <p class="text-xs text-zinc-550 dark:text-zinc-450 leading-relaxed mb-4">
        Our reviewers assess submissions for methodological soundness, empirical validity, and adherence to academic transparency standard practices.
      </p>
      <ul class="text-xs text-zinc-650 dark:text-zinc-400 space-y-2 list-disc pl-4 font-semibold">
        <li>Dr. Marcus Aurelius (Philosophical Science, Rome)</li>
        <li>Dr. Jane Goodall (Ethological Systems, Cambridge)</li>
        <li>Dr. Ada Lovelace (Computational History, London)</li>
      </ul>
    </div>
    <div>
      <h3 class="text-base font-bold text-zinc-900 dark:text-zinc-100 mb-3">Contact the Board</h3>
      <p class="text-xs text-zinc-550 dark:text-zinc-450 leading-relaxed mb-4">
        For inquiries regarding manuscript scopes, peer-review timelines, or joining the advisory panel, contact the editorial office.
      </p>
      <div class="bg-zinc-100 dark:bg-zinc-900/50 rounded-lg p-4 text-xs font-mono text-zinc-800 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-800">
        Email: editorial@scholarlynest.com<br/>
        Office: +1 (800) 555-0199
      </div>
    </div>
  </div>
</div>
HTML;

        $text = "Editorial Board\n\nScholarlyNest is guided by a distinguished international board of scholars, researchers, and editors who ensure the highest standards of scientific accuracy, academic rigor, and open-access publication integrity.\n\nEditor-in-Chief: Dr. Evelyn Reed (MIT)\nAssociate Editors:\n- Dr. Alistair Vance (Oxford)\n- Dr. Sofia Rodriguez (Stanford)\n- Dr. Kenji Sato (Tokyo Tech)\n- Dr. Elena Rostova (Max Planck)\n\nReviewers Board:\n- Dr. Marcus Aurelius\n- Dr. Jane Goodall\n- Dr. Ada Lovelace";

        CmsPage::updateOrCreate(
            ['slug' => 'editorial-board'],
            [
                'title' => 'Editorial Board',
                'content_html' => $html,
                'content_text' => $text,
                'is_active' => true,
            ]
        );
    }
}
