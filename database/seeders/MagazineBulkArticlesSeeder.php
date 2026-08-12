<?php

namespace Database\Seeders;

use App\Models\Magazine;
use App\Models\Article;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MagazineBulkArticlesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable query logger to conserve memory
        DB::disableQueryLog();

        // Retrieve or create a fallback author user
        $authorUser = User::where('email', 'admin@scholarlynest.com')->first() 
            ?? User::first();

        if (!$authorUser) {
            $authorUser = User::create([
                'name' => 'Dr. Evelyn Reed (Admin)',
                'email' => 'admin@scholarlynest.com',
                'password' => bcrypt('admin12345'),
                'email_verified_at' => now(),
            ]);
        }

        $unsplashImages = [
            'https://images.unsplash.com/photo-1507413245164-6160d8298b31?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1532094349884-543bc11b234d?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1532187643603-ba119ca4109e?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1507668077129-56e32842fceb?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1507679799987-c73779587ccf?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1593113598332-cd288d649433?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=800&q=80',
        ];

        $prefixes = [
            'Optimization of',
            'An Empirical Study of',
            'Comparative Analysis of',
            'Next-Generation Paradigms in',
            'Machine Learning Applications for',
            'A Framework for',
            'Investigation into',
            'Modeling and Simulation of',
            'Exploring the Limits of',
            'A Novel Approach to',
        ];

        $subjects = [
            'Quantum Cryptography Protocols',
            'Distributed Telemetry Networks',
            'Sustainable Agri-Tech Frameworks',
            'Advanced Bio-Safety Control Loops',
            'Nanostructured Thermal Dissipation Interfaces',
            'Autonomous Drone Path Planning',
            'Decentralized Combinatorial Auction Design',
            'Isotopic Silicon Spin Qubits',
            'Transcatheter Valve Outcomes',
            'fMRI Cortical Brain Mappings',
        ];

        $suffixes = [
            'in Modern Cloud Environments',
            'in South Asia Regional Sectors',
            'for Industrial Manufacturing Telemetry',
            'under Adverse Metrological Scenarios',
            'with Closed-Loop Feedback Controls',
            'to Enhance Systemic Liveness',
            'in High-Congestion Environments',
            'Using Deep Reinforcement Models',
        ];

        $rejectionReasons = [
            'The manuscript lacks sufficient statistical telemetry validation.',
            'Methodological rigor does not align with the standard peer-review expectations.',
            'The experimental control parameters contain excessive noise margins.',
            'The research duplication results are incomplete or unverifiable.',
        ];

        $startDate = Carbon::create(2012, 1, 1, 0, 0, 0);
        $endDate = Carbon::create(2026, 6, 4, 0, 0, 0);
        $totalSeconds = $startDate->diffInSeconds($endDate);
        $stepSeconds = $totalSeconds / 150;

        $magazines = Magazine::all();

        DB::beginTransaction();

        try {
            foreach ($magazines as $magazine) {
                $articlesData = [];

                for ($i = 0; $i < 150; $i++) {
                    // Generate unique academic-sounding title
                    $prefix = $prefixes[($i + $magazine->id) % count($prefixes)];
                    $subject = $subjects[($i * $magazine->id) % count($subjects)];
                    $suffix = $suffixes[($i + 13) % count($suffixes)];
                    
                    $title = "{$prefix} {$subject} {$suffix}";
                    $slug = Str::slug($title) . '-' . Str::random(6);

                    // Compute chronological timestamps
                    $publishDate = $startDate->copy()->addSeconds((int)($i * $stepSeconds));

                    // Status distribution: 70% Published, 10% Approved, 10% Submitted/Under Review/Resubmitted, 10% Rejections
                    $statusIndicator = $i % 10;
                    if ($statusIndicator < 7) {
                        $status = 'published';
                        $rejectionReason = null;
                        $publishedAt = $publishDate;
                    } elseif ($statusIndicator === 7) {
                        $status = 'approved';
                        $rejectionReason = null;
                        $publishedAt = $publishDate;
                    } elseif ($statusIndicator === 8) {
                        $status = ($i % 3 === 0) ? 'submitted' : (($i % 3 === 1) ? 'under_review' : 'resubmitted');
                        $rejectionReason = null;
                        $publishedAt = null;
                    } else {
                        $status = ($i % 2 === 0) ? 'minor_review_rejected' : 'fully_rejected';
                        $rejectionReason = $rejectionReasons[($i + $magazine->id) % count($rejectionReasons)];
                        $publishedAt = null;
                    }

                    $publishedYear = ($status === 'published') ? (int)$publishDate->format('Y') : null;
                    $publishedMonth = ($status === 'published') ? $publishDate->format('F') : null;

                    // Select Unsplash images
                    $img1 = $unsplashImages[($i) % count($unsplashImages)];
                    $img2 = $unsplashImages[($i + 1) % count($unsplashImages)];
                    $img3 = $unsplashImages[($i + 2) % count($unsplashImages)];

                    $abstract = "<p>This research paper presents a comprehensive examination of {$title}. We analyze operational constraints and present empirical telemetry results validating our theoretical models.</p>";

                    $fullText = "<h1>1. Introduction and Architectural Background</h1>";
                    $fullText .= "<p>The study of {$subject} has emerged as a key focal area in modern scientific research. Traditional modeling approaches struggle to address non-linear scale parameters, leading to systematic inefficiencies.</p>";
                    $fullText .= '<div class="my-8 flex flex-col items-center"><img src="' . $img1 . '" alt="Figure 1: Experimental setup" class="rounded-xl max-w-full h-auto border border-zinc-200" /></div>';
                    $fullText .= "<h2>2. Methodology and Simulation Framework</h2>";
                    $fullText .= "<p>We deploy closed-loop sensor grids to gather diagnostics telemetry. Testing configurations are calibrated to restrict background environmental noise.</p>";
                    $fullText .= '<div class="my-8 flex flex-col items-center"><img src="' . $img2 . '" alt="Figure 2: Performance analysis" class="rounded-xl max-w-full h-auto border border-zinc-200" /></div>';
                    $fullText .= "<h2>3. Statistical Outputs and Discussion</h2>";
                    $fullText .= "<p>The data confirms that optimizing resource buffers limits latency degradations by up to 24% under extreme traffic conditions.</p>";
                    $fullText .= '<div class="my-8 flex flex-col items-center"><img src="' . $img3 . '" alt="Figure 3: Core telemetry charts" class="rounded-xl max-w-full h-auto border border-zinc-200" /></div>';
                    $fullText .= "<h2>4. Conclusions</h2>";
                    $fullText .= "<p>In conclusion, the proposed methodology successfully maintains operational thresholds. Future work will investigate scaling limits.</p>";

                    $articlesData[] = [
                        'magazine_id' => $magazine->id,
                        'user_id' => $authorUser->id,
                        'title' => $title,
                        'slug' => $slug,
                        'abstract' => $abstract,
                        'full_text' => $fullText,
                        'pdf_path' => null,
                        'status' => $status,
                        'rejection_reason' => $rejectionReason,
                        'published_year' => $publishedYear,
                        'published_month' => $publishedMonth,
                        'clicks' => rand(5, 50),
                        'impressions' => rand(60, 400),
                        'published_at' => $publishedAt,
                        'created_at' => $publishDate,
                        'updated_at' => $publishDate,
                    ];
                }

                // Chunk and mass insert to prevent DB timeouts
                foreach (array_chunk($articlesData, 50) as $chunk) {
                    DB::table('articles')->insert($chunk);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
