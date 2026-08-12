<?php

namespace Database\Seeders;

use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Models\Article;
use App\Models\User;
use App\Models\ArticleAsset;
use App\Models\ArticleAuthor;
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
        $adminUser = User::where('email', 'info@scholarlynest.com')->first() 
            ?? User::first();

        // Update fallback admin user to have a university name if not already set
        if ($adminUser && !$adminUser->university_name) {
            $adminUser->update(['university_name' => 'Stanford University']);
        }

        $authorRole = \App\Models\Role::where('name', 'author')->first();

        // Create multiple unique author users
        $authorsInfo = [
            ['name' => 'Dr. Evelyn Reed', 'email' => 'evelyn@scholarlynest.com', 'univ' => 'Stanford University'],
            ['name' => 'Prof. Marcus Vance', 'email' => 'marcus@scholarlynest.com', 'univ' => 'MIT'],
            ['name' => 'Dr. Clara Oswald', 'email' => 'clara@scholarlynest.com', 'univ' => 'Oxford University'],
            ['name' => 'Prof. Liam Neeson', 'email' => 'liam@scholarlynest.com', 'univ' => 'Harvard University'],
            ['name' => 'Dr. Sarah Connor', 'email' => 'sarah@scholarlynest.com', 'univ' => 'Caltech'],
        ];

        $authorUsers = [];
        if ($adminUser) {
            $authorUsers[] = $adminUser;
        }

        foreach ($authorsInfo as $info) {
            $authorUsers[] = User::create([
                'name' => $info['name'],
                'email' => $info['email'],
                'password' => \Illuminate\Support\Facades\Hash::make('password123'),
                'email_verified_at' => now(),
                'role_id' => $authorRole?->id,
                'university_name' => $info['univ'],
            ]);
        }

        // 1. Seed 20 Premium Magazines
        $magazinesData = [
            [
                'title' => 'Nature Computing & AI',
                'slug' => 'nature-computing-ai',
                'description' => 'A monthly high-impact magazine detailing breakthrough achievements in machine learning models, distributed telemetry, and semantic artificial intelligence.',
                'about_text' => 'Nature Computing & AI is an open-access magazine covering foundational theories, implementation mechanics, and neural interface protocols. We publish monthly issues containing verified articles.'
            ],
            [
                'title' => 'IEEE Frontiers in Bioengineering',
                'slug' => 'ieee-frontiers-bioengineering',
                'description' => 'Reviewing next-generation cellular sequencing, gene editing safety validation, and robotic surgical enhancements.',
                'about_text' => 'IEEE Frontiers in Bioengineering is a global standard for bio-computational advances. Established in 2024, our community focuses on bridging medical engineering with computer science.'
            ],
            [
                'title' => 'Scholarly Review of Astrophysics',
                'slug' => 'scholarly-review-astrophysics',
                'description' => 'Covering black hole event horizons, cosmic microwave background radiation mapping, and computational orbital physics.',
                'about_text' => 'We curate space research telemetry logs, star cluster mapping datasets, and state-of-the-art astrophysical theorems for researchers worldwide.'
            ],
            [
                'title' => 'Magazine of Quantum Information Science',
                'slug' => 'magazine-quantum-information',
                'description' => 'Covering quantum key distribution, entanglement teleportation, and quantum computer gate alignment.',
                'about_text' => 'This publication provides peer-reviewed research on modern quantum state manipulation, algorithms, and logical gates.'
            ],
            [
                'title' => 'Review of Molecular Medicine & Genetics',
                'slug' => 'review-molecular-medicine',
                'description' => 'Investigating CRISPR gene drives, hereditary tracking algorithms, and molecular pathways.',
                'about_text' => 'Bridging clinical genetics with computational chemistry, this review features advanced molecular telemetry.'
            ],
            [
                'title' => 'Computational Ecology & Biodiversity',
                'slug' => 'computational-ecology-biodiversity',
                'description' => 'Analyzing Starling flock telemetry, star cluster migrations, and climate zone transformations.',
                'about_text' => 'Dedicated to statistical analysis of planetary biomes, animal migration tracking, and eco-system stability.'
            ],
            [
                'title' => 'Advanced Renewable Energy & Telemetry',
                'slug' => 'advanced-renewable-energy',
                'description' => 'Exploring smart grid telemetry, tidal power generators, and micro-grid battery arrays.',
                'about_text' => 'Covers modern hardware grids, renewable source dynamics, and smart grid battery control algorithms.'
            ],
            [
                'title' => 'Magazine of Robotics and Cybernetic Systems',
                'slug' => 'magazine-robotics-cybernetics',
                'description' => 'Highlighting path planning, robotic surgery control loops, and feedback actuator dynamics.',
                'about_text' => 'Our monthly magazine contains peer-reviewed papers on automation, kinematics, and control loop safety.'
            ],
            [
                'title' => 'International Magazine of Cognitive Systems',
                'slug' => 'cognitive-systems',
                'description' => 'Detailing neural network models of human perception, language decoding, and cognitive memory.',
                'about_text' => 'Publishes interdisciplinary research combining cognitive sciences, computer science, and linguistics.'
            ],
            [
                'title' => 'Frontiers in Applied Nanotechnology',
                'slug' => 'applied-nanotechnology',
                'description' => 'Researching carbon nanotube semiconductors, molecular motors, and nano-fluidics.',
                'about_text' => 'Focused on materials engineering at the sub-micron scale, micro-circuits, and smart nano-sensors.'
            ],
            [
                'title' => 'Astrophysics & Space Explorations',
                'slug' => 'astrophysics-space-explorations',
                'description' => 'Analyzing deep space satellite telemetry, warp dynamics, and lunar colony habitats.',
                'about_text' => 'Our registry indexes sub-orbital space telemetry data, black hole imaging methods, and deep space telemetry.'
            ],
            [
                'title' => 'Marine Oceanographic Research Logs',
                'slug' => 'marine-oceanographic-research',
                'description' => 'Telemetry from deep-sea trenches, coral bleaching rates, and sub-aquatic acoustics.',
                'about_text' => 'We catalog thermal ocean streams, acoustic migration logs, and oceanic pressure models.'
            ],
            [
                'title' => 'Magazine of Advanced Particle Physics',
                'slug' => 'advanced-particle-physics',
                'description' => 'Detailing hadron collider collisions, dark matter search vectors, and sub-atomic telemetry.',
                'about_text' => 'Covers advanced quantum physics, collision data analytics, and global particle physics telemetry.'
            ],
            [
                'title' => 'Renewable Agricultural Technology Review',
                'slug' => 'renewable-agricultural-tech',
                'description' => 'Reviewing smart soil moisture sensors, robotic harvesting, and organic crop genetics.',
                'about_text' => 'Published monthly, this review focuses on green farming algorithms, agricultural statistics, and genetics.'
            ],
            [
                'title' => 'International Review of Cryptographic Safety',
                'slug' => 'cryptographic-safety',
                'description' => 'Analyzing post-quantum cryptography, side-channel attacks, and consensus security.',
                'about_text' => 'Indexed research papers on zero-knowledge proofs, homomorphic encryption, and ledger security.'
            ],
            [
                'title' => 'Urban Informatics & Smart Cities Magazine',
                'slug' => 'urban-informatics-smart-cities',
                'description' => 'Reviewing autonomous traffic routing, municipal sensor networks, and waste logistics.',
                'about_text' => 'Focused on urban automation, intelligent transit grids, and smart municipal resource monitoring.'
            ],
            [
                'title' => 'Magazine of Volcanology & Seismic Studies',
                'slug' => 'volcanology-seismic-studies',
                'description' => 'Seismic tremor warning arrays, volcanic gas telemetry, and tectonic drift models.',
                'about_text' => 'Indexes geological telemetry datasets, volcanic warning protocols, and fault line drift models.'
            ],
            [
                'title' => 'Advanced Human-Computer Interfaces',
                'slug' => 'advanced-human-computer-interfaces',
                'description' => 'Reviewing eye tracking telemetry, haptic feedback actuators, and spatial computing.',
                'about_text' => 'Covers research on augmented reality, haptic response, and human factor telemetry modeling.'
            ],
            [
                'title' => 'Frontiers in Biotechnology & Biosensors',
                'slug' => 'biotechnology-biosensors',
                'description' => 'Reviewing blood-glucose telemetry, wear-resistant biosensors, and enzymatic fuel cells.',
                'about_text' => 'Focused on real-time biological data collection, enzyme telemetry, and biosensor materials.'
            ],
            [
                'title' => 'Magazine of Climatology & Global Dynamics',
                'slug' => 'climatology-global-dynamics',
                'description' => 'Modeling climate thermal anomalies, polar ice melt rates, and tropospheric winds.',
                'about_text' => 'We index global climate models, satellite troposphere telemetry, and weather anomalies.'
            ]
        ];

        $coverImages = [
            '/images/nature_computing.png',
            '/images/bioengineering.png',
            '/images/astrophysics.png',
        ];

        // Semantic components for unique title generator
        $prefixes = [
            'Analysis of', 'Optimizing', 'Investigation into', 'A Study on', 'Security Frameworks for', 
            'A Survey of', 'Next-Generation Design of', 'Empirical Telemetry of', 'Algorithmic Approaches to', 
            'Physical Constraints on', 'Comparative Evaluation of', 'Exploring the Limits of', 'Machine Learning for',
            'Advanced Architecture of', 'Mathematical Modelling of'
        ];

        $subjects = [
            'Distributed Ledger Algorithms', 'Quantum Entangled Packet Routing', 'Deep Learning Model Compression',
            'Bio-Synthetic Neural Interfaces', 'Cellular Genomic Sequencing Protocols', 'Sub-Orbital Astro-Telemetry Packets',
            'High-Efficiency Photovoltaic Conversions', 'Autonomous Robotic Surgical Manipulation', 'Consensus Verification Loops',
            'Low-Latency Edge Computing Clusters', 'Dynamic Cryptographic Encryption Models', 'Seismic Wave Propagation Models',
            'Thermodynamic Recycling Circuits', 'Atmospheric Carbon Absorption Dynamics', 'Bionic Biosensor Telemetry Channels'
        ];

        $contexts = [
            'in Heterogeneous Environments', 'under Adversary Noise Constraints', 'for Remote Space Explorations',
            'in Modern Smart Infrastructures', 'across Global Dissemination Networks', 'for Patient Diagnostic Safety',
            'in High-Density Silicon Wafers', 'under Low-Power Operations', 'using Hybrid Consensus Vector Mechanics',
            'applied to Real-Time Planetary Telemetry', 'within Scaled Collaborative Publish Arrays'
        ];

        // Time limits: 05-01-2012 to 04-06-2026
        // If parsed as MM-DD-YYYY: 2012-05-01 (May 1st, 2012) to 2026-04-06 (April 6th, 2026)
        // If parsed as DD-MM-YYYY: 2012-01-05 (January 5th, 2012) to 2026-06-04 (June 4th, 2026)
        // We use a range from 2012-05-01 to 2026-06-04 which covers the requested range.
        $startTimestamp = strtotime('2012-05-01');
        $endTimestamp = strtotime('2026-06-04');

        $universities = [
            'MIT', 'Stanford University', 'Harvard University', 
            'California Institute of Technology', 'Oxford University', 
            'Cambridge University', 'Princeton University', 
            'Yale University', 'UC Berkeley', 'ETH Zurich'
        ];

        $coAuthorNames = [
            'Dr. Alan Turing', 'Dr. Grace Hopper', 'Dr. Richard Feynman',
            'Prof. Claude Shannon', 'Prof. Ada Lovelace', 'Dr. Tim Berners-Lee',
            'Prof. John von Neumann', 'Dr. Margaret Hamilton', 'Prof. Alan Kay',
            'Dr. Donald Knuth', 'Prof. Barbara Liskov', 'Dr. Edsger Dijkstra'
        ];

        foreach ($magazinesData as $m => $magData) {
            // Cycle cover images
            $magData['cover_image'] = $coverImages[$m % count($coverImages)];
            
            // Seed SEO fields for magazine
            $magData['seo_title'] = $magData['title'] . ' | ScholarlyNest';
            $magData['seo_description'] = Str::limit($magData['description'], 150);
            $magData['seo_keywords'] = implode(', ', [
                str_replace(' ', '', strtolower($magData['title'])),
                'research',
                'magazine',
                'science',
                'technology',
                'scholarlynest'
            ]);

            $magazine = Magazine::create($magData);

            // 2. Seed custom sorted pages for each magazine
            MagazinePage::create([
                'magazine_id' => $magazine->id,
                'title' => 'Editorial Board & Standards',
                'slug' => 'editorial-board-' . $magazine->id,
                'content' => '<h3>Editorial Governance Block</h3><p>Our editorial board consists of leading international researchers with decades of experience in telemetry, computation, and submission guidelines. All submissions are reviewed to guarantee supreme academic neutrality.</p>',
                'sort_order' => 1
            ]);

            MagazinePage::create([
                'magazine_id' => $magazine->id,
                'title' => 'Manuscript Submission Guidelines',
                'slug' => 'submission-guidelines-' . $magazine->id,
                'content' => '<h3>Submission Protocols</h3><p>Authors must submit original, unpublished research articles. Standard format requires a clean abstract under 300 words, detailed methodologies, and citation links conforming to the OAI-PMH metadata schemas.</p>',
                'sort_order' => 2
            ]);

            // 3. Seed exactly 50 Articles for each magazine
            for ($i = 0; $i < 50; $i++) {
                // Generate highly unique title combinations
                $pIdx = ($m * 50 + $i) % count($prefixes);
                $sIdx = ($m * 7 + $i * 3) % count($subjects);
                $cIdx = ($m * 13 + $i * 17) % count($contexts);
                
                $title = $prefixes[$pIdx] . ' ' . $subjects[$sIdx] . ' ' . $contexts[$cIdx];
                $slug = Str::slug($title);

                // Distribute statuses: 40 Published, 5 Approved, 2 Submitted, 1 Under Review, 1 Minor Review Rejected, 1 Fully Rejected
                if ($i < 40) {
                    $status = 'published';
                } elseif ($i < 45) {
                    $status = 'approved';
                } elseif ($i < 47) {
                    $status = 'submitted';
                } elseif ($i === 47) {
                    $status = 'under_review';
                } elseif ($i === 48) {
                    $status = 'minor_review_rejected';
                } else {
                    $status = 'fully_rejected';
                }

                // Random dates within boundaries
                $randomTimestamp = rand($startTimestamp, $endTimestamp);
                $publishedAtDate = (in_array($status, ['approved', 'published'])) ? date('Y-m-d H:i:s', $randomTimestamp) : null;
                
                $publishedYear = ($status === 'published') ? (int)date('Y', $randomTimestamp) : null;
                $publishedMonth = ($status === 'published') ? date('F', $randomTimestamp) : null;

                $createdAtDate = date('Y-m-d H:i:s', $randomTimestamp);

                $pdfPath = (in_array($status, ['approved', 'published']) && $i % 3 === 0) ? 'storage/manuscripts/research_paper_' . ($m * 50 + $i) . '.pdf' : null;
                $featuredImage = ($i % 7 === 0) ? 'storage/articles/feature_image_' . ($m * 50 + $i) . '.jpg' : null;

                $abstract = "<p>This research presents a detailed investigation regarding " . strtolower($subjects[$sIdx]) . " " . $contexts[$cIdx] . ". We demonstrate that optimization of these parameters yields a significant improvement in overall system telemetry.</p>";
                $fullText = "<h1>Introduction</h1><p>The study of " . strtolower($subjects[$sIdx]) . " has become vital in modern scientific research. Here we analyze its core components.</p><h2>Methodology</h2><p>Our approach measures performance under adverse constraints, collecting real-time telemetry records.</p><h2>Conclusion</h2><p>Results confirm the theoretical models proposed in our study.</p>";

                // Random clicks and impressions
                $clicks = rand(5, 120);
                $impressions = rand($clicks + 10, $clicks + 350);

                // Generate dynamic SEO metadata
                $seoTitle = Str::limit($title, 60) . ' | ScholarlyNest';
                $seoDescription = Str::limit(strip_tags($abstract), 155);
                $cleanTitleWords = array_unique(explode(' ', strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $title))));
                $seoKeywords = implode(', ', array_slice($cleanTitleWords, 0, 8));

                // Choose a random author user from our array
                $assignedAuthor = $authorUsers[($m * 50 + $i) % count($authorUsers)];

                $article = Article::create([
                    'magazine_id' => $magazine->id,
                    'user_id' => $assignedAuthor->id,
                    'title' => $title,
                    'slug' => $slug,
                    'abstract' => $abstract,
                    'full_text' => $fullText,
                    'pdf_path' => $pdfPath,
                    'featured_image' => $featuredImage,
                    'status' => $status,
                    'rejection_reason' => (in_array($status, ['minor_review_rejected', 'fully_rejected'])) ? 'The submission violates basic telemetry guidelines and lacks peer-validated empirical data.' : null,
                    'published_at' => $publishedAtDate,
                    'published_year' => $publishedYear,
                    'published_month' => $publishedMonth,
                    'created_at' => $createdAtDate,
                    'updated_at' => $createdAtDate,
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'seo_title' => $seoTitle,
                    'seo_description' => $seoDescription,
                    'seo_keywords' => $seoKeywords,
                ]);

                // Seed co-authors for 30% of approved, published, or under-review articles
                if (in_array($status, ['approved', 'published', 'submitted', 'under_review', 'resubmitted']) && $i % 3 === 0) {
                    $numCoAuthors = rand(1, 2);
                    for ($c = 0; $c < $numCoAuthors; $c++) {
                        $coAuthorIndex = ($m * 50 + $i * 2 + $c) % count($coAuthorNames);
                        $coAuthorName = $coAuthorNames[$coAuthorIndex];
                        $coAuthorEmail = strtolower(str_replace([' ', '.'], '', $coAuthorName)) . '@scholarlynest.com';
                        $coAuthorUniv = $universities[($m * 50 + $i * 2 + $c) % count($universities)];

                        ArticleAuthor::create([
                            'article_id' => $article->id,
                            'co_author_name' => $coAuthorName,
                            'co_author_email' => $coAuthorEmail,
                            'can_edit' => rand(0, 1) === 1,
                            'account_provisioned' => false,
                            'university_name' => $coAuthorUniv,
                            'created_at' => $createdAtDate,
                            'updated_at' => $createdAtDate,
                        ]);
                    }
                }

                // Seed supplementary files for every 5th approved article
                if ($status === 'approved' && $i % 5 === 0) {
                    ArticleAsset::create([
                        'article_id' => $article->id,
                        'file_path' => 'storage/assets/dataset_' . $article->id . '.csv',
                        'original_filename' => 'research_data_subset_' . $article->id . '.csv',
                        'file_size' => rand(50000, 150000), // ~50KB - ~150KB
                        'mime_type' => 'text/csv',
                    ]);

                    ArticleAsset::create([
                        'article_id' => $article->id,
                        'file_path' => 'storage/assets/presentation_' . $article->id . '.pdf',
                        'original_filename' => 'presentation_slides_' . $article->id . '.pdf',
                        'file_size' => rand(1000000, 3000000), // ~1MB - ~3MB
                        'mime_type' => 'application/pdf',
                    ]);
                }
            }
        }
    }
}
