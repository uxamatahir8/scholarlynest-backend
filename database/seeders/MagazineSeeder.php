<?php

namespace Database\Seeders;

use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Models\Article;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MagazineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Fetch existing users to associate as authors
        $authorUser = User::where('email', 'author@scholarlynest.com')->first() 
            ?? User::first();
        $adminUser = User::where('email', 'admin@scholarlynest.com')->first() 
            ?? User::first();

        // 1. Seed Premium Magazines
        $magazines = [
            [
                'title' => 'Nature Computing & AI',
                'slug' => 'nature-computing-ai',
                'cover_image' => '/images/nature_computing.png',
                'description' => 'A monthly high-impact magazine detailing breakthrough achievements in machine learning models, distributed telemetry, and semantic artificial intelligence.',
                'about_text' => 'Nature Computing & AI is an open-access magazine covering foundational theories, implementation mechanics, and neural interface protocols. We publish monthly issues containing verified articles.'
            ],
            [
                'title' => 'IEEE Frontiers in Bioengineering',
                'slug' => 'ieee-frontiers-bioengineering',
                'cover_image' => '/images/bioengineering.png',
                'description' => 'Reviewing next-generation cellular sequencing, gene editing safety validation, and robotic surgical enhancements.',
                'about_text' => 'IEEE Frontiers in Bioengineering is a global standard for bio-computational advances. Established in 2024, our community focuses on bridging medical engineering with computer science.'
            ],
            [
                'title' => 'Scholarly Review of Astrophysics',
                'slug' => 'scholarly-review-astrophysics',
                'cover_image' => '/images/astrophysics.png',
                'description' => 'Covering black hole event horizons, cosmic microwave background radiation mapping, and computational orbital physics.',
                'about_text' => 'We curate space research telemetry logs, star cluster mapping datasets, and state-of-the-art astrophysical theorems for researchers worldwide.'
            ]
        ];

        foreach ($magazines as $magData) {
            $magazine = Magazine::create($magData);

            // 2. Seed custom sorted pages for each magazine
            MagazinePage::create([
                'magazine_id' => $magazine->id,
                'title' => 'Editorial Board & Standards',
                'slug' => 'editorial-board',
                'content' => '<h3>Editorial Governance Block</h3><p>Our editorial board consists of leading international researchers with decades of experience in telemetry, computation, and submission guidelines. All submissions are reviewed to guarantee supreme academic neutrality.</p>',
                'sort_order' => 1
            ]);

            MagazinePage::create([
                'magazine_id' => $magazine->id,
                'title' => 'Manuscript Submission Guidelines',
                'slug' => 'submission-guidelines',
                'content' => '<h3>Submission Protocols</h3><p>Authors must submit original, unpublished research articles. Standard format requires a clean abstract under 300 words, detailed methodologies, and citation links conforming to the OAI-PMH metadata schemas.</p>',
                'sort_order' => 2
            ]);

            // 3. Seed Articles for each magazine
            // Article 1: Approved (we will let the system generate PDF if null, but let's leave pdf_path null to test dynamic generator, or seed a path)
            Article::create([
                'magazine_id' => $magazine->id,
                'user_id' => $authorUser->id,
                'title' => 'Distributed Decentralized Ledgers in AI Training Networks',
                'slug' => Str::slug('Distributed Decentralized Ledgers in AI Training Networks') . '-' . Str::random(4),
                'abstract' => '<p>This paper examines structural frameworks for decentralized telemetry ledger integration within federated neural model training systems. We prove that using consensus algorithms improves telemetry security by 40%.</p>',
                'full_text' => '<h1>Introduction</h1><p>Decentralized model training systems have recently gained popularity due to the scaling limits of homogeneous server clusters. However, distributed synchronization introduces potential risks of corrupt model state injection.</p><h2>Methodology</h2><p>We propose a dynamic consensus weight vector that adjusts block validity based on historically verified author signatures.</p><h2>Conclusion</h2><p>Experimental results prove robust stability under standard adverse simulation constraints.</p>',
                'pdf_path' => null, // Let's keep it null so the PDF will be generated upon admin review, or pre-approved
                'status' => 'approved',
            ]);

            // Article 2: Pending
            Article::create([
                'magazine_id' => $magazine->id,
                'user_id' => $authorUser->id,
                'title' => 'Quantum Telemetry Interoperability Constraints',
                'slug' => Str::slug('Quantum Telemetry Interoperability Constraints') . '-' . Str::random(4),
                'abstract' => '<p>Exploring the physical limits of quantum entangled telemetry packets and their transmission properties over sub-orbital fiber lines.</p>',
                'full_text' => '<h1>Introduction</h1><p>Quantum keys are secure, but physical noise affects overall data throughput.</p><h2>Discussion</h2><p>We evaluate classical error-correction methods applied to quantum states.</p>',
                'pdf_path' => null,
                'status' => 'pending',
            ]);

            // Article 3: Rejected
            Article::create([
                'magazine_id' => $magazine->id,
                'user_id' => $authorUser->id,
                'title' => 'Perpetual Thermodynamic Computing Platforms',
                'slug' => Str::slug('Perpetual Thermodynamic Computing Platforms') . '-' . Str::random(4),
                'abstract' => '<p>A theoretical design for zero-energy computation circuits that recycle ambient thermal entropy into operations.</p>',
                'full_text' => '<h1>Introduction</h1><p>This design violates the second law of thermodynamics, but we present it anyway.</p>',
                'pdf_path' => null,
                'status' => 'rejected',
                'rejection_reason' => 'The submission violates basic thermodynamic physical laws and lacks empirical peer-validated telemetry data.'
            ]);
        }
    }
}
