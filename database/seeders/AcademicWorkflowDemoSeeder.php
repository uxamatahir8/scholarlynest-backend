<?php

namespace Database\Seeders;

use App\Constants\ArticleStatus;
use App\Constants\SystemRoles;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\MagazinePage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AcademicWorkflowDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        try {
            // 1. Temporarily disable foreign key constraints
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF');
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            }

            // 2. Truncate workflow tables in safe dependency order
            $tables = [
                'editor_sub_editor',
                'article_tag',
                'article_share_clicks',
                'review_question_responses',
                'review_questionnaire_instances',
                'article_publication_sections',
                'article_reviewer_preferences',
                'article_author',
                'article_assets',
                'article_files',
                'article_versions',
                'article_audit_logs',
                'sub_editor_assignments',
                'reviewer_assignments',
                'editorial_decisions',
                'production_assignments',
                'post_publication_actions',
                'workflow_deadline_reminder_logs',
                'review_question_options',
                'review_questions',
                'review_questionnaire_versions',
                'review_questionnaires',
                'magazine_issues',
                'magazine_pages',
                'magazine_user',
                'articles',
                'magazines',
            ];

            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }

            // Re-enable foreign key constraints
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            }

            // 3. Remove existing demo users created by this seeder
            $demoEmails = [
                'author1@example.com', 'author2@example.com', 'author3@example.com',
                'editor1@example.com', 'editor2@example.com', 'editor3@example.com',
                'subeditor1@example.com', 'subeditor2@example.com', 'subeditor3@example.com',
                'reviewer1@example.com', 'reviewer2@example.com', 'reviewer3@example.com',
                'publisher1@example.com', 'publisher2@example.com', 'publisher3@example.com',
                'copyeditor1@example.com', 'copyeditor2@example.com', 'copyeditor3@example.com',
                'proofreader1@example.com', 'proofreader2@example.com', 'proofreader3@example.com',
                'demo.superadmin@example.com',
                'accepted.external.reviewer@example.com',
            ];

            DB::table('users')->whereIn('email', $demoEmails)->delete();

            DB::beginTransaction();

            // 4. Ensure roles exist and fetch their IDs
            $roles = Role::pluck('id', 'name')->toArray();
            foreach (SystemRoles::DEFINITIONS as $name => $definition) {
                if (!isset($roles[$name])) {
                    $role = Role::create([
                        'name' => $name,
                        'display_name' => $definition['display_name'],
                        'description' => $definition['description'],
                        'is_system' => true,
                    ]);
                    $roles[$name] = $role->id;
                }
            }

            // 5. Seed Users
            $password = Hash::make('Password123!');
            $userDefinitions = [
                'super_admin' => [
                    ['name' => 'Demo Super Admin', 'email' => 'demo.superadmin@example.com']
                ],
                'author' => [
                    ['name' => 'Author One', 'email' => 'author1@example.com'],
                    ['name' => 'Author Two', 'email' => 'author2@example.com'],
                    ['name' => 'Author Three', 'email' => 'author3@example.com']
                ],
                'editor' => [
                    ['name' => 'Editor One', 'email' => 'editor1@example.com'],
                    ['name' => 'Editor Two', 'email' => 'editor2@example.com'],
                    ['name' => 'Editor Three', 'email' => 'editor3@example.com']
                ],
                'sub_editor' => [
                    ['name' => 'Sub Editor One', 'email' => 'subeditor1@example.com'],
                    ['name' => 'Sub Editor Two', 'email' => 'subeditor2@example.com'],
                    ['name' => 'Sub Editor Three', 'email' => 'subeditor3@example.com']
                ],
                'reviewer' => [
                    ['name' => 'Reviewer One', 'email' => 'reviewer1@example.com'],
                    ['name' => 'Reviewer Two', 'email' => 'reviewer2@example.com'],
                    ['name' => 'Reviewer Three', 'email' => 'reviewer3@example.com']
                ],
                'publisher' => [
                    ['name' => 'Publisher One', 'email' => 'publisher1@example.com'],
                    ['name' => 'Publisher Two', 'email' => 'publisher2@example.com'],
                    ['name' => 'Publisher Three', 'email' => 'publisher3@example.com']
                ],
                'copy_editor' => [
                    ['name' => 'Copy Editor One', 'email' => 'copyeditor1@example.com'],
                    ['name' => 'Copy Editor Two', 'email' => 'copyeditor2@example.com'],
                    ['name' => 'Copy Editor Three', 'email' => 'copyeditor3@example.com']
                ],
                'proofreader' => [
                    ['name' => 'Proofreader One', 'email' => 'proofreader1@example.com'],
                    ['name' => 'Proofreader Two', 'email' => 'proofreader2@example.com'],
                    ['name' => 'Proofreader Three', 'email' => 'proofreader3@example.com']
                ],
            ];

            $usersByRole = [];
            foreach ($userDefinitions as $roleName => $users) {
                $roleId = $roles[$roleName] ?? null;
                foreach ($users as $u) {
                    $user = User::create([
                        'name' => $u['name'],
                        'email' => $u['email'],
                        'password' => $password,
                        'role_id' => $roleId,
                        'email_verified_at' => now(),
                        'university_name' => 'Academic Demo University',
                    ]);
                    $usersByRole[$roleName][] = $user;
                }
            }

            $superAdmin = $usersByRole['super_admin'][0];
            $acceptedExternalReviewer = User::create([
                'name' => 'Accepted External Reviewer',
                'email' => 'accepted.external.reviewer@example.com',
                'password' => $password,
                'role_id' => $roles['reviewer'] ?? null,
                'email_verified_at' => now(),
                'needs_password_reset' => true,
                'university_name' => 'External Peer Review Institute',
            ]);

            $questionnaireSeed = $this->seedReviewerQuestionnaire($superAdmin->id);

            // Seed Editor-Sub Editor relationships
            $editors = $usersByRole['editor'] ?? [];
            $subEditors = $usersByRole['sub_editor'] ?? [];

            if (count($editors) >= 3 && count($subEditors) >= 3) {
                DB::table('editor_sub_editor')->insert([
                    [
                        'editor_id' => $editors[0]->id,
                        'sub_editor_id' => $subEditors[0]->id,
                        'created_by' => $superAdmin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'editor_id' => $editors[0]->id,
                        'sub_editor_id' => $subEditors[1]->id,
                        'created_by' => $superAdmin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'editor_id' => $editors[1]->id,
                        'sub_editor_id' => $subEditors[1]->id,
                        'created_by' => $superAdmin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'editor_id' => $editors[1]->id,
                        'sub_editor_id' => $subEditors[2]->id,
                        'created_by' => $superAdmin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'editor_id' => $editors[2]->id,
                        'sub_editor_id' => $subEditors[2]->id,
                        'created_by' => $superAdmin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);
            }

            // 6. Magazines Data
            $magazineData = [
                [
                    'title' => 'Nature Computing & AI',
                    'slug' => 'nature-computing-ai',
                    'description' => 'A monthly high-impact magazine detailing breakthrough achievements in machine learning models, distributed telemetry, and semantic artificial intelligence.',
                    'about_text' => 'Nature Computing & AI is an open-access magazine covering foundational theories, implementation mechanics, and neural interface protocols.'
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
            ];

            $magIssues = [];
            $magToEditor = [];
            $magToPublisher = [];

            foreach ($magazineData as $idx => $m) {
                $editorIndex = (int)($idx / 4);
                $publisherIndex = (int)($idx / 4);

                $assignedEditor = $usersByRole['editor'][$editorIndex];
                $assignedPublisher = $usersByRole['publisher'][$publisherIndex];

                $mag = Magazine::create([
                    'title' => $m['title'],
                    'slug' => $m['slug'],
                    'description' => $m['description'],
                    'about_text' => $m['about_text'],
                    'cover_image' => '/images/nature_computing.png',
                    'seo_title' => $m['title'] . ' | ScholarlyNest',
                    'seo_description' => Str::limit($m['description'], 150),
                    'seo_keywords' => 'research, magazine, ' . str_replace(' ', '', strtolower($m['title'])),
                ]);

                $magToEditor[$mag->id] = $assignedEditor;
                $magToPublisher[$mag->id] = $assignedPublisher;

                // Seed sample pages
                MagazinePage::create([
                    'magazine_id' => $mag->id,
                    'title' => 'Editorial Board',
                    'slug' => 'editorial-board-' . $mag->id,
                    'content' => '<p>Editorial board governance information.</p>',
                    'sort_order' => 1,
                    'created_by' => $assignedEditor->id,
                    'created_by_role' => 'editor',
                    'is_editor_created' => true,
                ]);

                MagazinePage::create([
                    'magazine_id' => $mag->id,
                    'title' => 'Submission Guidelines',
                    'slug' => 'submission-guidelines-' . $mag->id,
                    'content' => '<p>Guidelines for manuscript submission.</p>',
                    'sort_order' => 2,
                    'created_by' => $assignedEditor->id,
                    'created_by_role' => 'editor',
                    'is_editor_created' => true,
                ]);

                // Pivot assignments
                DB::table('magazine_user')->insert([
                    [
                        'user_id' => $assignedEditor->id,
                        'magazine_id' => $mag->id,
                        'role' => 'editor',
                        'assigned_by' => $superAdmin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'user_id' => $assignedPublisher->id,
                        'magazine_id' => $mag->id,
                        'role' => 'publisher',
                        'assigned_by' => $superAdmin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);

                // 3 Issues per magazine (2 published, 1 draft)
                $issue1Id = DB::table('magazine_issues')->insertGetId([
                    'magazine_id' => $mag->id,
                    'volume_number' => 1,
                    'issue_number' => 1,
                    'special_title' => 'Volume 1, Issue 1',
                    'description' => 'Launch Issue',
                    'cover_image' => '/images/bioengineering.png',
                    'status' => 'published',
                    'is_published' => true,
                    'published_at' => now()->subMonths(6),
                    'issue_month' => 'January',
                    'issue_year' => 2025,
                    'created_at' => now()->subMonths(6),
                    'updated_at' => now()->subMonths(6),
                ]);

                $issue2Id = DB::table('magazine_issues')->insertGetId([
                    'magazine_id' => $mag->id,
                    'volume_number' => 1,
                    'issue_number' => 2,
                    'special_title' => 'Volume 1, Issue 2',
                    'description' => 'Summer Issue',
                    'cover_image' => '/images/bioengineering.png',
                    'status' => 'published',
                    'is_published' => true,
                    'published_at' => now()->subMonths(2),
                    'issue_month' => 'July',
                    'issue_year' => 2025,
                    'created_at' => now()->subMonths(2),
                    'updated_at' => now()->subMonths(2),
                ]);

                $issue3Id = DB::table('magazine_issues')->insertGetId([
                    'magazine_id' => $mag->id,
                    'volume_number' => 2,
                    'issue_number' => 1,
                    'special_title' => 'Volume 2, Issue 1',
                    'description' => 'Forthcoming Issue',
                    'cover_image' => '/images/bioengineering.png',
                    'status' => 'draft',
                    'is_published' => false,
                    'published_at' => null,
                    'issue_month' => 'January',
                    'issue_year' => 2026,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $magIssues[$mag->id] = [$issue1Id, $issue2Id, $issue3Id];
            }

            // 7. Seed Articles (550 per magazine, total 6,600 to satisfy density requirement)
            $statuses = [
                'draft',
                'submitted',
                'under_review',
                'assigned_to_sub_editor',
                'reviewer_assigned',
                'review_in_progress',
                'revision_required',
                'minor_revision_required',
                'major_revision_required',
                'resubmitted',
                'accepted',
                'rejected',
                'copy_editing',
                'proofreading',
                'ready_for_publication',
                'published',
                'withdrawn',
                'archived',
            ];

            $authors = $usersByRole['author'];
            $subEditors = $usersByRole['sub_editor'];
            $reviewers = $usersByRole['reviewer'];
            $copyEditors = $usersByRole['copy_editor'];
            $proofreaders = $usersByRole['proofreader'];

            $authorsToInsert = [];
            $versionsToInsert = [];
            $filesToInsert = [];
            $assetsToInsert = [];
            $reviewerPreferencesToInsert = [];
            $publicationSectionsToInsert = [];
            $subEditorAssignmentsToInsert = [];
            $reviewerAssignmentsToInsert = [];
            $editorialDecisionsToInsert = [];
            $productionAssignmentsToInsert = [];
            $auditLogsToInsert = [];

            // Global predictable IDs for referencing
            $globalVersionId = 1;
            $globalSubEditorAssignmentId = 1;

            $magazinesList = Magazine::all();

            $startTimestamp = strtotime('2015-01-23 09:00:00');
            $endTimestamp = strtotime('2026-06-19 17:00:00');
            $secondsDiff = $endTimestamp - $startTimestamp;
            $totalArticlesPerMagazine = 550;
            $seededPendingExternalInvitation = false;
            $seededAcceptedExternalInvitation = false;
            $seededDeclinedExternalInvitation = false;

            foreach ($magazinesList as $mag) {
                $editorUser = $magToEditor[$mag->id];
                $publisherUser = $magToPublisher[$mag->id];

                for ($artIndex = 0; $artIndex < $totalArticlesPerMagazine; $artIndex++) {
                    $statusIndex = (int)($artIndex / 30);
                    if ($statusIndex >= 18) {
                        $status = 'published';
                    } else {
                        $status = $statuses[$statusIndex];
                    }

                    $authorUser = $authors[$artIndex % 3];

                    // Linear interpolation from Jan 23, 2015 to Jun 19, 2026 with 4 hours max random jitter
                    $interpolatedTimestamp = $startTimestamp + (int)(($artIndex / ($totalArticlesPerMagazine - 1)) * $secondsDiff);
                    $jitter = rand(-14400, 14400);
                    $timestamp = max($startTimestamp, min($endTimestamp, $interpolatedTimestamp + $jitter));
                    $createdAt = now()->setTimestamp($timestamp);

                    $title = "Academic Study on " . $mag->title . " - Phase " . ($artIndex + 1);
                    $slug = Str::slug($title) . '-' . uniqid();

                    $magazineIssueId = null;
                    $publishedAt = null;
                    $publishedYear = null;
                    $publishedMonth = null;
                    $doi = null;
                    $pageStart = null;
                    $pageEnd = null;
                    $openAccessLabel = null;
                    $isPeerReviewed = true;
                    $academicEditor = null;
                    $receivedAt = null;
                    $acceptedAt = null;
                    $licenseStatement = null;
                    $competingInterestsStatement = null;
                    $abbreviations = null;
                    $citationText = null;

                    if ($status === 'published') {
                        $magazineIssueId = $magIssues[$mag->id][$artIndex % 2];
                        $publishedAt = $createdAt->copy()->addDays(15);
                        if ($publishedAt->getTimestamp() > $endTimestamp) {
                            $publishedAt = now()->setTimestamp($endTimestamp);
                        }
                        $publishedYear = (int)$publishedAt->format('Y');
                        $publishedMonth = $publishedAt->format('F');
                        $doi = "10.1234/sn." . $mag->slug . "." . ($artIndex + 1);
                        $pageStart = 1 + ($artIndex * 12);
                        $pageEnd = 12 + ($artIndex * 12);
                        $openAccessLabel = ($artIndex % 4 === 0) ? 'Open Access' : null;
                        $academicEditor = 'Dr. Helena Park';
                        $receivedAt = $createdAt->copy()->subDays(14)->toDateString();
                        $acceptedAt = $createdAt->copy()->addDays(13)->toDateString();
                        $licenseStatement = 'Copyright 2026 The Authors. Published by ScholarlyNest under CC BY 4.0.';
                        $competingInterestsStatement = 'The authors declare no competing interests.';
                        $abbreviations = 'AI: Artificial Intelligence; SNR: Signal-to-noise ratio';
                        $citationText = "Demo Author ({$publishedYear}). {$title}. {$mag->title}. https://doi.org/{$doi}";
                    } elseif (in_array($status, ['ready_for_publication', 'copy_editing', 'proofreading'], true)) {
                        $openAccessLabel = 'Publication Draft';
                        $academicEditor = 'Dr. Helena Park';
                        $licenseStatement = 'Draft license statement for publisher review.';
                        $competingInterestsStatement = 'Draft competing interests statement.';
                    }

                    $abstract = "This manuscript details a rigorous academic investigation into the key theoretical parameters of the field. We present telemetry data, methodology, and a comprehensive analysis of the results.";
                    $fullText = "<h3>Introduction</h3><p>Academic workflows require consistent peer review.</p><h3>Methodology</h3><p>We simulated 550 articles across 12 magazines to validate system performance.</p><h3>Conclusion</h3><p>Our findings indicate that proper user assignment reduces editorial bottlenecks.</p>";

                    $plagiarismStatus = null;
                    $plagiarismScore = null;
                    $plagiarismReportPath = null;
                    $screenedAt = null;
                    $screenedBy = null;

                    $hasScreening = !in_array($status, ['draft', 'submitted']);
                    if ($hasScreening) {
                        $plagiarismStatus = ($artIndex % 10 === 0) ? 'warning' : 'passed';
                        $plagiarismScore = ($artIndex % 10 === 0) ? 28.50 : 8.20;
                        $plagiarismReportPath = "storage/plagiarism/plagiarism_report_" . $artIndex . ".pdf";
                        $screenedAt = $createdAt->copy()->addDays(2);
                        $screenedBy = $editorUser->id;
                        
                        $this->ensureFileExists($plagiarismReportPath);
                    }

                    $articleId = DB::table('articles')->insertGetId([
                        'magazine_id' => $mag->id,
                        'magazine_issue_id' => $magazineIssueId,
                        'user_id' => $authorUser->id,
                        'title' => $title,
                        'subtitle' => "Subtitle details for " . $title,
                        'slug' => $slug,
                        'abstract' => $abstract,
                        'keywords' => json_encode(['academic', 'workflow', 'simulation', 'testing']),
                        'article_category' => 'Original Research',
                        'article_type' => 'Research Article',
                        'subject_area' => 'Computational Science',
                        'language' => 'en',
                        'ethical_approval_statement' => 'Ethical approval was obtained from the Institutional Review Board.',
                        'conflict_of_interest_statement' => 'The authors declare no conflict of interest.',
                        'funding_statement' => 'This work was supported by the Academic Research Foundation.',
                        'data_availability_statement' => 'All simulation data is available upon request.',
                        'author_contribution_statement' => 'Author One designed the study. Co-authors assisted in simulation tuning.',
                        'full_text' => $fullText,
                        'pdf_path' => ($status === 'published') ? "storage/publications/publication_" . $artIndex . ".pdf" : null,
                        'featured_image' => ($artIndex % 5 === 0) ? '/images/nature_computing.png' : null,
                        'doi' => $doi,
                        'open_access_label' => $openAccessLabel,
                        'is_peer_reviewed' => $isPeerReviewed,
                        'academic_editor' => $academicEditor,
                        'received_at' => $receivedAt,
                        'accepted_at' => $acceptedAt,
                        'license_statement' => $licenseStatement,
                        'competing_interests_statement' => $competingInterestsStatement,
                        'abbreviations' => $abbreviations,
                        'citation_text' => $citationText,
                        'status' => $status,
                        'rejection_reason' => ($status === 'rejected') ? 'The submission does not meet the novelty standards of this magazine.' : null,
                        'plagiarism_status' => $plagiarismStatus,
                        'plagiarism_score' => $plagiarismScore,
                        'plagiarism_report_path' => $plagiarismReportPath,
                        'screened_at' => $screenedAt,
                        'screened_by' => $screenedBy,
                        'clicks' => ($status === 'published') ? rand(10, 100) : 0,
                        'impressions' => ($status === 'published') ? rand(100, 500) : 0,
                        'published_at' => $publishedAt,
                        'published_year' => $publishedYear,
                        'published_month' => $publishedMonth,
                        'page_start' => $pageStart,
                        'page_end' => $pageEnd,
                        'seo_title' => $title . " | ScholarlyNest",
                        'seo_description' => Str::limit($abstract, 150),
                        'seo_keywords' => 'academic, workflow, ' . $mag->slug,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    DB::table('articles')->where('id', $articleId)->update([
                        'tracking_code' => sprintf('SN-%s-%06d', $createdAt->format('Y'), $articleId),
                    ]);

                    if ($status === 'published') {
                        $this->ensureFileExists("storage/publications/publication_" . $artIndex . ".pdf");
                    }

                    $preferenceScenario = $artIndex % 4;
                    if (in_array($preferenceScenario, [0, 2], true)) {
                        foreach ($this->suggestedReviewerPreferences($articleId, $authorUser->id, $artIndex) as $preference) {
                            $reviewerPreferencesToInsert[] = array_merge($preference, [
                                'created_at' => $createdAt,
                                'updated_at' => $createdAt,
                            ]);
                        }
                    }
                    if (in_array($preferenceScenario, [1, 2], true)) {
                        foreach ($this->opposingReviewerPreferences($articleId, $authorUser->id, $artIndex) as $preference) {
                            $reviewerPreferencesToInsert[] = array_merge($preference, [
                                'created_at' => $createdAt,
                                'updated_at' => $createdAt,
                            ]);
                        }
                    }

                    if ($artIndex % 6 === 0) {
                        $supplementPath = "storage/supplementary/supplement_" . $articleId . ".pdf";
                        $this->ensureFileExists($supplementPath);
                        $assetsToInsert[] = $this->articleAssetRow(
                            $articleId,
                            'supplementary',
                            $supplementPath,
                            'supplementary-methods.pdf',
                            'application/pdf',
                            20480,
                            null,
                            null,
                            null,
                            0,
                            $createdAt
                        );
                    }

                    if ($artIndex % 5 === 0 || $status === 'published') {
                        $imagePath = "storage/article-images/figure_" . $articleId . ".webp";
                        $this->ensureFileExists($imagePath);
                        $assetsToInsert[] = $this->articleAssetRow(
                            $articleId,
                            'image',
                            $imagePath,
                            'figure-1.webp',
                            'image/webp',
                            8192,
                            'Figure 1',
                            'Workflow summary and assay response map.',
                            'Seeded article image for publication display testing.',
                            1,
                            $createdAt
                        );
                    }

                    if ($status === 'published') {
                        foreach ($this->publicationSectionRows($articleId, $artIndex % 5 === 0, $createdAt) as $section) {
                            $publicationSectionsToInsert[] = $section;
                        }
                    } elseif (in_array($status, ['ready_for_publication', 'copy_editing'], true) && $artIndex % 30 === 0) {
                        foreach ($this->publicationSectionRows($articleId, false, $createdAt) as $section) {
                            $publicationSectionsToInsert[] = $section;
                        }
                    }

                    // Primary Author
                    $authorsToInsert[] = [
                        'article_id' => $articleId,
                        'user_id' => $authorUser->id,
                        'co_author_name' => $authorUser->name,
                        'co_author_email' => $authorUser->email,
                        'affiliation' => 'Stanford University',
                        'department' => 'Computer Science Department',
                        'country' => 'United States',
                        'orcid' => '0000-0002-1825-0097',
                        'author_order' => 1,
                        'is_owner' => true,
                        'is_corresponding' => true,
                        'contribution_statement' => 'Conceived and designed the research experiments.',
                        'can_edit' => true,
                        'account_provisioned' => true,
                        'university_name' => 'Stanford University',
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];

                    // Co-Author for 30% of articles
                    if ($artIndex % 3 === 0) {
                        $authorsToInsert[] = [
                            'article_id' => $articleId,
                            'user_id' => null,
                            'co_author_name' => "Dr. Evelyn Reed",
                            'co_author_email' => "evelyn.coauthor@example.com",
                            'affiliation' => 'Harvard University',
                            'department' => 'Bioengineering Division',
                            'country' => 'United States',
                            'orcid' => '0000-0003-4921-1200',
                            'author_order' => 2,
                            'is_owner' => false,
                            'is_corresponding' => false,
                            'contribution_statement' => 'Assisted in model tuning and methodology review.',
                            'can_edit' => false,
                            'account_provisioned' => false,
                            'university_name' => 'Harvard University',
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ];
                    }

                    // Version 1 (Submitted)
                    $hasVersion1 = $status !== 'draft';
                    if ($hasVersion1) {
                        $version1Id = $globalVersionId++;
                        $versionsToInsert[] = [
                            'id' => $version1Id,
                            'article_id' => $articleId,
                            'created_by' => $authorUser->id,
                            'version_number' => 1,
                            'label' => 'v1.0',
                            'status_snapshot' => 'submitted',
                            'metadata_snapshot' => json_encode(['title' => $title, 'abstract' => $abstract]),
                            'file_snapshot' => json_encode([['file_type' => 'manuscript', 'original_name' => 'original_manuscript.pdf']]),
                            'change_summary' => 'Initial submission of the manuscript.',
                            'author_response' => null,
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ];

                        $manuscriptPath = "storage/manuscripts/manuscript_" . $articleId . "_v1.pdf";
                        $this->ensureFileExists($manuscriptPath);

                        $filesToInsert[] = [
                            'article_id' => $articleId,
                            'article_version_id' => $version1Id,
                            'source_asset_id' => null,
                            'uploaded_by' => $authorUser->id,
                            'assignment_type' => null,
                            'assignment_id' => null,
                            'file_type' => 'manuscript',
                            'visibility' => 'workflow',
                            'file_path' => $manuscriptPath,
                            'original_name' => 'original_manuscript.pdf',
                            'mime_type' => 'application/pdf',
                            'size' => 10240,
                            'metadata' => null,
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ];

                        $auditLogsToInsert[] = [
                            'article_id' => $articleId,
                            'actor_id' => $authorUser->id,
                            'event' => 'submitted',
                            'from_status' => null,
                            'to_status' => 'submitted',
                            'payload' => json_encode(['ip' => '127.0.0.1']),
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ];
                    }

                    if ($hasScreening) {
                        $auditLogsToInsert[] = [
                            'article_id' => $articleId,
                            'actor_id' => $editorUser->id,
                            'event' => 'screening_started',
                            'from_status' => 'submitted',
                            'to_status' => 'under_review',
                            'payload' => null,
                            'created_at' => $createdAt->copy()->addDays(2),
                            'updated_at' => $createdAt->copy()->addDays(2),
                        ];
                    }

                    // Sub Editor Assignment
                    $hasSubEditor = in_array($status, [
                        'assigned_to_sub_editor', 'reviewer_assigned', 'review_in_progress',
                        'revision_required', 'minor_revision_required', 'major_revision_required',
                        'resubmitted', 'accepted', 'copy_editing', 'proofreading',
                        'ready_for_publication', 'published', 'archived'
                    ]);

                    if ($hasSubEditor) {
                        $validSubEditors = [];
                        if ($editorUser->email === 'editor1@example.com') {
                            $validSubEditors = [$subEditors[0], $subEditors[1]];
                        } elseif ($editorUser->email === 'editor2@example.com') {
                            $validSubEditors = [$subEditors[1], $subEditors[2]];
                        } else {
                            $validSubEditors = [$subEditors[2]];
                        }
                        $subEditorUser = $validSubEditors[$artIndex % count($validSubEditors)];
                        $seStatus = 'active';
                        $seCompletedAt = null;
                        $seRec = null;

                        $isCompletedSubEditor = in_array($status, [
                            'revision_required', 'minor_revision_required', 'major_revision_required',
                            'resubmitted', 'accepted', 'copy_editing', 'proofreading',
                            'ready_for_publication', 'published', 'archived'
                        ]);

                        if ($isCompletedSubEditor) {
                            $seStatus = 'completed';
                            $seCompletedAt = $createdAt->copy()->addDays(15);
                            $seRec = ($status === 'rejected') ? 'reject' : 'accept';
                        }

                        $subEditorAssignmentId = $globalSubEditorAssignmentId++;

                        $subEditorAssignmentsToInsert[] = [
                            'id' => $subEditorAssignmentId,
                            'article_id' => $articleId,
                            'sub_editor_id' => $subEditorUser->id,
                            'assigned_by' => $editorUser->id,
                            'status' => $seStatus,
                            'due_date' => $createdAt->copy()->addDays(14),
                            'completed_at' => $seCompletedAt,
                            'recommendation' => $seRec,
                            'comments' => 'Overall good submission, recommended proceeding.',
                            'created_at' => $createdAt->copy()->addDays(3),
                            'updated_at' => $createdAt->copy()->addDays(3),
                        ];

                        $auditLogsToInsert[] = [
                            'article_id' => $articleId,
                            'actor_id' => $editorUser->id,
                            'event' => 'sub_editor_assigned',
                            'from_status' => 'under_review',
                            'to_status' => 'assigned_to_sub_editor',
                            'payload' => json_encode(['sub_editor_id' => $subEditorUser->id]),
                            'created_at' => $createdAt->copy()->addDays(3),
                            'updated_at' => $createdAt->copy()->addDays(3),
                        ];
                    }

                    // Reviewer Assignment
                    $hasReviewer = in_array($status, [
                        'reviewer_assigned', 'review_in_progress',
                        'revision_required', 'minor_revision_required', 'major_revision_required',
                        'resubmitted', 'accepted', 'copy_editing', 'proofreading',
                        'ready_for_publication', 'published', 'archived'
                    ]);

                    if ($hasReviewer) {
                        $reviewerUser = $reviewers[$artIndex % 3];
                        $revStatus = 'pending';
                        $revAcceptedAt = null;
                        $revCompletedAt = null;
                        $revRec = null;
                        $revComments = null;

                        if ($status === 'review_in_progress') {
                            $revStatus = 'accepted';
                            $revAcceptedAt = $createdAt->copy()->addDays(5);
                        } elseif (in_array($status, [
                            'revision_required', 'minor_revision_required', 'major_revision_required',
                            'resubmitted', 'accepted', 'copy_editing', 'proofreading',
                            'ready_for_publication', 'published', 'archived'
                        ])) {
                            $revStatus = 'completed';
                            $revAcceptedAt = $createdAt->copy()->addDays(5);
                            $revCompletedAt = $createdAt->copy()->addDays(12);
                            $revRec = ($status === 'rejected') ? 'reject' : 'accept';
                            $revComments = 'I highly recommend this manuscript for publication.';
                        }

                        $reviewerAssignmentsToInsert[] = [
                            'article_id' => $articleId,
                            'sub_editor_assignment_id' => $subEditorAssignmentId,
                            'reviewer_id' => $reviewerUser->id,
                            'invitee_name' => $reviewerUser->name,
                            'invitee_email' => $reviewerUser->email,
                            'invite_token_hash' => null,
                            'invited_at' => $createdAt->copy()->addDays(4),
                            'invite_expires_at' => null,
                            'declined_at' => null,
                            'decline_reason' => null,
                            'account_created_at' => null,
                            'questionnaire_instance_id' => null,
                            'assigned_by' => $subEditorUser->id,
                            'status' => $revStatus,
                            'due_date' => $createdAt->copy()->addDays(21),
                            'accepted_at' => $revAcceptedAt,
                            'completed_at' => $revCompletedAt,
                            'scorecard' => json_encode(['originality' => 4, 'methodology' => 4, 'writing' => 3]),
                            'recommendation' => $revRec,
                            'comments_for_author' => $revComments,
                            'confidential_comments' => 'Solid paper.',
                            'created_at' => $createdAt->copy()->addDays(4),
                            'updated_at' => $createdAt->copy()->addDays(4),
                        ];

                        if (!$seededPendingExternalInvitation && $status === 'reviewer_assigned') {
                            $reviewerAssignmentsToInsert[] = $this->reviewerAssignmentRow(
                                $articleId,
                                $subEditorAssignmentId,
                                null,
                                'Dr. Priya External',
                                'pending.external.reviewer@example.com',
                                hash('sha256', 'demo-pending-review-token'),
                                $subEditorUser->id,
                                'pending',
                                $createdAt->copy()->addDays(24),
                                null,
                                null,
                                null,
                                null,
                                null,
                                $createdAt->copy()->addDays(6)
                            );
                            $seededPendingExternalInvitation = true;
                        }

                        if (!$seededAcceptedExternalInvitation && $status === 'review_in_progress') {
                            $reviewerAssignmentsToInsert[] = $this->reviewerAssignmentRow(
                                $articleId,
                                $subEditorAssignmentId,
                                $acceptedExternalReviewer->id,
                                $acceptedExternalReviewer->name,
                                $acceptedExternalReviewer->email,
                                null,
                                $subEditorUser->id,
                                'accepted',
                                $createdAt->copy()->addDays(24),
                                $createdAt->copy()->addDays(7),
                                null,
                                null,
                                null,
                                $createdAt->copy()->addDays(7),
                                $createdAt->copy()->addDays(6)
                            );
                            $seededAcceptedExternalInvitation = true;
                        }

                        if (!$seededDeclinedExternalInvitation && $status === 'reviewer_assigned') {
                            $reviewerAssignmentsToInsert[] = $this->reviewerAssignmentRow(
                                $articleId,
                                $subEditorAssignmentId,
                                null,
                                'Dr. Declined External',
                                'declined.external.reviewer@example.com',
                                null,
                                $subEditorUser->id,
                                'declined',
                                $createdAt->copy()->addDays(24),
                                null,
                                null,
                                $createdAt->copy()->addDays(8),
                                'Unavailable due to a competing review commitment.',
                                null,
                                $createdAt->copy()->addDays(6)
                            );
                            $seededDeclinedExternalInvitation = true;
                        }

                        $auditLogsToInsert[] = [
                            'article_id' => $articleId,
                            'actor_id' => $subEditorUser->id,
                            'event' => 'reviewer_assigned',
                            'from_status' => 'assigned_to_sub_editor',
                            'to_status' => 'reviewer_assigned',
                            'payload' => json_encode(['reviewer_id' => $reviewerUser->id]),
                            'created_at' => $createdAt->copy()->addDays(4),
                            'updated_at' => $createdAt->copy()->addDays(4),
                        ];
                    }

                    // Editorial Decisions
                    $hasDecision = in_array($status, [
                        'revision_required', 'minor_revision_required', 'major_revision_required',
                        'resubmitted', 'accepted', 'rejected', 'copy_editing', 'proofreading',
                        'ready_for_publication', 'published', 'archived'
                    ]);

                    if ($hasDecision) {
                        $decisionString = 'accepted';
                        if ($status === 'rejected') {
                            $decisionString = 'rejected';
                        } elseif (in_array($status, ['revision_required', 'minor_revision_required', 'major_revision_required'])) {
                            $decisionString = $status;
                        }

                        $editorialDecisionsToInsert[] = [
                            'article_id' => $articleId,
                            'decision_by' => $editorUser->id,
                            'decision' => $decisionString,
                            'decision_source' => 'editor',
                            'decision_date' => $createdAt->copy()->addDays(16),
                            'comments_for_author' => 'Please review the editor and reviewer remarks carefully.',
                            'internal_notes' => 'Checked for structure.',
                            'created_at' => $createdAt->copy()->addDays(16),
                            'updated_at' => $createdAt->copy()->addDays(16),
                        ];

                        $auditLogsToInsert[] = [
                            'article_id' => $articleId,
                            'actor_id' => $editorUser->id,
                            'event' => 'editorial_decision',
                            'from_status' => 'review_in_progress',
                            'to_status' => $decisionString,
                            'payload' => json_encode(['decision' => $decisionString]),
                            'created_at' => $createdAt->copy()->addDays(16),
                            'updated_at' => $createdAt->copy()->addDays(16),
                        ];
                    }

                    // Resubmitted (Version 2)
                    $hasVersion2 = in_array($status, [
                        'resubmitted', 'accepted', 'copy_editing', 'proofreading',
                        'ready_for_publication', 'published', 'archived'
                    ]);

                    if ($hasVersion2) {
                        $version2Id = $globalVersionId++;
                        $versionsToInsert[] = [
                            'id' => $version2Id,
                            'article_id' => $articleId,
                            'created_by' => $authorUser->id,
                            'version_number' => 2,
                            'label' => 'v2.0',
                            'status_snapshot' => 'resubmitted',
                            'metadata_snapshot' => json_encode(['title' => $title, 'abstract' => $abstract]),
                            'file_snapshot' => json_encode([['file_type' => 'manuscript', 'original_name' => 'resubmitted_manuscript.pdf']]),
                            'change_summary' => 'Addressed comments regarding methodology.',
                            'author_response' => 'I have revised the manuscript.',
                            'created_at' => $createdAt->copy()->addDays(20),
                            'updated_at' => $createdAt->copy()->addDays(20),
                        ];

                        $resubmittedPath = "storage/manuscripts/manuscript_" . $articleId . "_v2.pdf";
                        $this->ensureFileExists($resubmittedPath);

                        $filesToInsert[] = [
                            'article_id' => $articleId,
                            'article_version_id' => $version2Id,
                            'source_asset_id' => null,
                            'uploaded_by' => $authorUser->id,
                            'assignment_type' => null,
                            'assignment_id' => null,
                            'file_type' => 'manuscript',
                            'visibility' => 'workflow',
                            'file_path' => $resubmittedPath,
                            'original_name' => 'resubmitted_manuscript.pdf',
                            'mime_type' => 'application/pdf',
                            'size' => 11200,
                            'metadata' => null,
                            'created_at' => $createdAt->copy()->addDays(20),
                            'updated_at' => $createdAt->copy()->addDays(20),
                        ];

                        $auditLogsToInsert[] = [
                            'article_id' => $articleId,
                            'actor_id' => $authorUser->id,
                            'event' => 'resubmitted',
                            'from_status' => 'revision_required',
                            'to_status' => 'resubmitted',
                            'payload' => null,
                            'created_at' => $createdAt->copy()->addDays(20),
                            'updated_at' => $createdAt->copy()->addDays(20),
                        ];
                    }

                    // Copy Editing Production Assignment
                    $hasCopyEditingAssignment = in_array($status, [
                        'copy_editing', 'proofreading', 'ready_for_publication', 'published', 'archived'
                    ]);

                    if ($hasCopyEditingAssignment) {
                        $copyEditorUser = $copyEditors[$artIndex % 3];
                        $ceStatus = ($status === 'copy_editing') ? 'active' : 'completed';
                        $ceCompletedAt = ($status === 'copy_editing') ? null : $createdAt->copy()->addDays(28);

                        $productionAssignmentsToInsert[] = [
                            'article_id' => $articleId,
                            'user_id' => $copyEditorUser->id,
                            'role' => 'copy_editor',
                            'assigned_by' => $editorUser->id,
                            'status' => $ceStatus,
                            'due_date' => $createdAt->copy()->addDays(32),
                            'completed_at' => $ceCompletedAt,
                            'created_at' => $createdAt->copy()->addDays(23),
                            'updated_at' => $createdAt->copy()->addDays(23),
                        ];

                        $copyEditedPath = "storage/production/copy_edited_" . $articleId . ".docx";
                        $this->ensureFileExists($copyEditedPath);

                        $filesToInsert[] = [
                            'article_id' => $articleId,
                            'article_version_id' => $hasVersion2 ? $version2Id : $version1Id,
                            'source_asset_id' => null,
                            'uploaded_by' => $copyEditorUser->id,
                            'assignment_type' => 'production',
                            'assignment_id' => null,
                            'file_type' => 'copy_edited_file',
                            'visibility' => 'workflow',
                            'file_path' => $copyEditedPath,
                            'original_name' => 'copy_edited_version.docx',
                            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'size' => 12500,
                            'metadata' => null,
                            'created_at' => $createdAt->copy()->addDays(27),
                            'updated_at' => $createdAt->copy()->addDays(27),
                        ];

                        $auditLogsToInsert[] = [
                            'article_id' => $articleId,
                            'actor_id' => $editorUser->id,
                            'event' => 'copy_editor_assigned',
                            'from_status' => 'accepted',
                            'to_status' => 'copy_editing',
                            'payload' => json_encode(['copy_editor_id' => $copyEditorUser->id]),
                            'created_at' => $createdAt->copy()->addDays(23),
                            'updated_at' => $createdAt->copy()->addDays(23),
                        ];
                    }

                    // Proofreading Production Assignment
                    $hasProofreadingAssignment = in_array($status, [
                        'proofreading', 'ready_for_publication', 'published', 'archived'
                    ]);

                    if ($hasProofreadingAssignment) {
                        $proofreaderUser = $proofreaders[$artIndex % 3];
                        $prStatus = ($status === 'proofreading') ? 'active' : 'completed';
                        $prCompletedAt = ($status === 'proofreading') ? null : $createdAt->copy()->addDays(35);

                        $productionAssignmentsToInsert[] = [
                            'article_id' => $articleId,
                            'user_id' => $proofreaderUser->id,
                            'role' => 'proofreader',
                            'assigned_by' => $editorUser->id,
                            'status' => $prStatus,
                            'due_date' => $createdAt->copy()->addDays(39),
                            'completed_at' => $prCompletedAt,
                            'created_at' => $createdAt->copy()->addDays(29),
                            'updated_at' => $createdAt->copy()->addDays(29),
                        ];

                        $proofPath = "storage/production/proof_" . $articleId . ".pdf";
                        $this->ensureFileExists($proofPath);

                        $filesToInsert[] = [
                            'article_id' => $articleId,
                            'article_version_id' => $hasVersion2 ? $version2Id : $version1Id,
                            'source_asset_id' => null,
                            'uploaded_by' => $proofreaderUser->id,
                            'assignment_type' => 'production',
                            'assignment_id' => null,
                            'file_type' => 'proof_file',
                            'visibility' => 'workflow',
                            'file_path' => $proofPath,
                            'original_name' => 'final_proof.pdf',
                            'mime_type' => 'application/pdf',
                            'size' => 14000,
                            'metadata' => null,
                            'created_at' => $createdAt->copy()->addDays(34),
                            'updated_at' => $createdAt->copy()->addDays(34),
                        ];

                        $auditLogsToInsert[] = [
                            'article_id' => $articleId,
                            'actor_id' => $editorUser->id,
                            'event' => 'proofreader_assigned',
                            'from_status' => 'copy_editing',
                            'to_status' => 'proofreading',
                            'payload' => json_encode(['proofreader_id' => $proofreaderUser->id]),
                            'created_at' => $createdAt->copy()->addDays(29),
                            'updated_at' => $createdAt->copy()->addDays(29),
                        ];
                    }

                    if ($status === 'published') {
                        $auditLogsToInsert[] = [
                            'article_id' => $articleId,
                            'actor_id' => $publisherUser->id,
                            'event' => 'published',
                            'from_status' => 'ready_for_publication',
                            'to_status' => 'published',
                            'payload' => json_encode(['doi' => $doi, 'issue_id' => $magazineIssueId]),
                            'created_at' => $createdAt->copy()->addDays(40),
                            'updated_at' => $createdAt->copy()->addDays(40),
                        ];
                    }

                    if ($status === 'withdrawn' || $status === 'archived') {
                        $auditLogsToInsert[] = [
                            'article_id' => $articleId,
                            'actor_id' => $editorUser->id,
                            'event' => $status,
                            'from_status' => 'submitted',
                            'to_status' => $status,
                            'payload' => json_encode(['reason' => 'Administrative request.']),
                            'created_at' => $createdAt->copy()->addDays(15),
                            'updated_at' => $createdAt->copy()->addDays(15),
                        ];
                    }
                }
            }

            // 8. Bulk insert related data in 500-record chunks for maximum performance and SQL limits
            $bulkInsert = function (string $table, array $data) {
                foreach (array_chunk($data, 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            };

            $bulkInsert('article_author', $authorsToInsert);
            $bulkInsert('article_reviewer_preferences', $reviewerPreferencesToInsert);
            $bulkInsert('article_assets', $assetsToInsert);
            $bulkInsert('article_publication_sections', $publicationSectionsToInsert);
            $bulkInsert('article_versions', $versionsToInsert);
            $bulkInsert('article_files', $filesToInsert);
            $bulkInsert('sub_editor_assignments', $subEditorAssignmentsToInsert);
            $bulkInsert('reviewer_assignments', $reviewerAssignmentsToInsert);
            $bulkInsert('editorial_decisions', $editorialDecisionsToInsert);
            $bulkInsert('production_assignments', $productionAssignmentsToInsert);
            $bulkInsert('article_audit_logs', $auditLogsToInsert);

            $this->seedQuestionnaireInstancesAndResponses($questionnaireSeed);

            DB::commit();
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    /**
     * Helper to write placeholder files on local storage to prevent download 404s.
     */
    protected function seedReviewerQuestionnaire(int $superAdminId): array
    {
        $now = now();
        $questionnaireId = DB::table('review_questionnaires')->insertGetId([
            'name' => 'Academic Demo Reviewer Questionnaire',
            'is_active' => true,
            'created_by' => $superAdminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $versionId = DB::table('review_questionnaire_versions')->insertGetId([
            'review_questionnaire_id' => $questionnaireId,
            'version_number' => 1,
            'is_active' => true,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $questionDefinitions = [
            'suitability' => [
                'prompt' => 'Is the manuscript suitable for further review?',
                'response_type' => 'radio',
                'is_required' => true,
                'sort_order' => 1,
                'options' => ['Yes', 'No'],
            ],
            'improvements' => [
                'prompt' => 'Which areas require improvement?',
                'response_type' => 'checkbox',
                'is_required' => false,
                'sort_order' => 2,
                'options' => ['Methodology', 'References', 'Statistical analysis', 'Language clarity', 'Figures/tables'],
            ],
            'decision' => [
                'prompt' => 'Recommended editorial decision',
                'response_type' => 'dropdown',
                'is_required' => true,
                'sort_order' => 3,
                'options' => ['Accept', 'Minor revision', 'Major revision', 'Reject'],
            ],
            'contribution' => [
                'prompt' => 'State the main contribution of the manuscript in one sentence.',
                'response_type' => 'single_line',
                'is_required' => true,
                'sort_order' => 4,
                'options' => [],
            ],
            'editor_comments' => [
                'prompt' => 'Provide detailed comments for the editor.',
                'response_type' => 'textarea',
                'is_required' => true,
                'sort_order' => 5,
                'options' => [],
            ],
        ];

        $questions = [];
        $options = [];
        foreach ($questionDefinitions as $key => $definition) {
            $questionId = DB::table('review_questions')->insertGetId([
                'review_questionnaire_version_id' => $versionId,
                'prompt' => $definition['prompt'],
                'response_type' => $definition['response_type'],
                'is_required' => $definition['is_required'],
                'sort_order' => $definition['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $questions[$key] = $questionId;

            foreach ($definition['options'] as $index => $label) {
                $value = Str::slug($label);
                DB::table('review_question_options')->insert([
                    'review_question_id' => $questionId,
                    'label' => $label,
                    'value' => $value,
                    'sort_order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $options[$key][$label] = $value;
            }
        }

        return [
            'version_id' => $versionId,
            'questions' => $questions,
            'options' => $options,
        ];
    }

    protected function seedQuestionnaireInstancesAndResponses(array $questionnaireSeed): void
    {
        $pendingAssignment = DB::table('reviewer_assignments')
            ->where('invitee_email', 'pending.external.reviewer@example.com')
            ->first();
        if ($pendingAssignment) {
            $instanceId = DB::table('review_questionnaire_instances')->insertGetId([
                'article_id' => $pendingAssignment->article_id,
                'reviewer_assignment_id' => $pendingAssignment->id,
                'reviewer_id' => null,
                'review_questionnaire_version_id' => $questionnaireSeed['version_id'],
                'submitted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('reviewer_assignments')->where('id', $pendingAssignment->id)->update([
                'questionnaire_instance_id' => $instanceId,
            ]);
        }

        $completedAssignment = DB::table('reviewer_assignments')
            ->where('status', 'completed')
            ->whereNotNull('reviewer_id')
            ->orderBy('id')
            ->first();
        if (!$completedAssignment) {
            return;
        }

        $instanceId = DB::table('review_questionnaire_instances')->insertGetId([
            'article_id' => $completedAssignment->article_id,
            'reviewer_assignment_id' => $completedAssignment->id,
            'reviewer_id' => $completedAssignment->reviewer_id,
            'review_questionnaire_version_id' => $questionnaireSeed['version_id'],
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('reviewer_assignments')->where('id', $completedAssignment->id)->update([
            'questionnaire_instance_id' => $instanceId,
        ]);

        $responses = [
            $questionnaireSeed['questions']['suitability'] => $questionnaireSeed['options']['suitability']['Yes'],
            $questionnaireSeed['questions']['improvements'] => [
                $questionnaireSeed['options']['improvements']['Methodology'],
                $questionnaireSeed['options']['improvements']['Figures/tables'],
            ],
            $questionnaireSeed['questions']['decision'] => $questionnaireSeed['options']['decision']['Minor revision'],
            $questionnaireSeed['questions']['contribution'] => 'The manuscript contributes a reproducible workflow for interdisciplinary peer review.',
            $questionnaireSeed['questions']['editor_comments'] => 'The article is technically sound and would benefit from a clearer statistical analysis paragraph before acceptance.',
        ];

        foreach ($responses as $questionId => $answer) {
            DB::table('review_question_responses')->insert([
                'review_questionnaire_instance_id' => $instanceId,
                'review_question_id' => $questionId,
                'answer' => json_encode($answer),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function suggestedReviewerPreferences(int $articleId, int $authorId, int $index): array
    {
        return [
            [
                'article_id' => $articleId,
                'created_by_author_id' => $authorId,
                'type' => 'suggested',
                'name' => 'Dr. Ayesha Khan',
                'email' => 'ayesha.khan.demo' . $index . '@example.com',
                'affiliation' => 'Lahore Institute of Food Chemistry',
                'designation' => 'Professor of Bioactive Compounds',
                'reason' => 'Expert in food chemistry and bioactive compounds.',
            ],
            [
                'article_id' => $articleId,
                'created_by_author_id' => $authorId,
                'type' => 'suggested',
                'name' => 'Dr. Michael Turner',
                'email' => 'michael.turner.demo' . $index . '@example.com',
                'affiliation' => 'Northbridge Antioxidant Assay Center',
                'designation' => 'Senior Reviewer',
                'reason' => 'Experienced in article peer review and antioxidant assays.',
            ],
        ];
    }

    protected function opposingReviewerPreferences(int $articleId, int $authorId, int $index): array
    {
        return [[
            'article_id' => $articleId,
            'created_by_author_id' => $authorId,
            'type' => 'opposed',
            'name' => 'Dr. Conflict Reviewer',
            'email' => 'conflict.reviewer.demo' . $index . '@example.com',
            'affiliation' => 'Competing Lab for Applied Assays',
            'designation' => 'Principal Investigator',
            'reason' => 'Competing lab and declared conflict of interest.',
        ]];
    }

    protected function reviewerAssignmentRow(
        int $articleId,
        ?int $subEditorAssignmentId,
        ?int $reviewerId,
        string $inviteeName,
        string $inviteeEmail,
        ?string $tokenHash,
        int $assignedBy,
        string $status,
        $dueDate,
        $acceptedAt,
        $completedAt,
        $declinedAt,
        ?string $declineReason,
        $accountCreatedAt,
        $createdAt
    ): array {
        return [
            'article_id' => $articleId,
            'sub_editor_assignment_id' => $subEditorAssignmentId,
            'reviewer_id' => $reviewerId,
            'invitee_name' => $inviteeName,
            'invitee_email' => $inviteeEmail,
            'invite_token_hash' => $tokenHash,
            'invited_at' => $createdAt,
            'invite_expires_at' => $tokenHash ? now()->addDays(21) : null,
            'declined_at' => $declinedAt,
            'decline_reason' => $declineReason,
            'account_created_at' => $accountCreatedAt,
            'questionnaire_instance_id' => null,
            'assigned_by' => $assignedBy,
            'status' => $status,
            'due_date' => $dueDate,
            'accepted_at' => $acceptedAt,
            'completed_at' => $completedAt,
            'scorecard' => null,
            'recommendation' => null,
            'comments_for_author' => null,
            'confidential_comments' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    protected function articleAssetRow(
        int $articleId,
        string $assetType,
        string $path,
        string $filename,
        string $mimeType,
        int $fileSize,
        ?string $title,
        ?string $caption,
        ?string $description,
        int $sortOrder,
        $createdAt
    ): array {
        return [
            'article_id' => $articleId,
            'asset_type' => $assetType,
            'disk' => 'public',
            'file_path' => $path,
            'storage_key' => str_replace('storage/', '', $path),
            'original_filename' => $filename,
            'safe_original_filename' => $filename,
            'title' => $title,
            'caption' => $caption,
            'description' => $description,
            'sort_order' => $sortOrder,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'checksum_sha256' => hash('sha256', $path),
            'scan_status' => 'clean',
            'scan_engine' => 'demo-seeder',
            'scanned_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    protected function publicationSectionRows(int $articleId, bool $partial, $createdAt): array
    {
        $sections = [
            'introduction' => '<h2>Introduction</h2><p>This article introduces a reproducible academic workflow for evaluating interdisciplinary submissions.</p>',
            'materials_and_methods' => '<h2>Materials and methods</h2><p>The study uses simulated manuscript metadata, reviewer scorecards, and publication records.</p>',
            'discussion' => '<h2>Discussion</h2><p>The seeded results demonstrate how editorial state, review activity, and publication metadata connect.</p>',
            'supporting_information' => '<h2>Supporting information</h2><p>Supplementary PDF and image assets are provided as clean demo records.</p>',
            'acknowledgements' => '<h2>Acknowledgements</h2><p>The authors thank the demo editorial team for workflow validation.</p>',
            'references' => '<h2>References</h2><ol><li>ScholarlyNest Demo Consortium. Academic workflow validation. 2026.</li></ol>',
        ];

        if ($partial) {
            $sections = array_intersect_key($sections, array_flip(['introduction', 'discussion']));
        }

        return collect($sections)->map(fn ($html, $key) => [
            'article_id' => $articleId,
            'section_key' => $key,
            'content_html' => $html,
            'content_text' => trim(html_entity_decode(strip_tags($html))),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->values()->all();
    }

    protected function ensureFileExists(string $path): void
    {
        $diskPath = str_replace('storage/', '', $path);
        if (!Storage::disk('public')->exists($diskPath)) {
            Storage::disk('public')->put($diskPath, 'This is a demo/testing PDF manuscript file content placeholder.');
        }
    }
}
