<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticlePublicationSection;
use App\Models\ArticleReviewerPreference;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\ReviewerAssignment;
use App\Models\ReviewQuestionnaire;
use App\Models\ReviewQuestionnaireInstance;
use App\Models\ReviewQuestionResponse;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AcademicWorkflowDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AcademicWorkflowDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_workflow_demo_seeder_populates_correct_records(): void
    {
        $this->seed(AcademicWorkflowDemoSeeder::class);

        // 1. Verify 6 magazines exist
        $this->assertEquals(6, Magazine::count());

        // 2. Verify 1650 articles exist (275 per magazine)
        $this->assertEquals(1650, Article::count());

        // 3. Verify users created per role
        $demoEmails = [
            'author1@example.com', 'author2@example.com', 'author3@example.com',
            'editor1@example.com', 'editor2@example.com', 'editor3@example.com',
            'subeditor1@example.com', 'subeditor2@example.com', 'subeditor3@example.com',
            'reviewer1@example.com', 'reviewer2@example.com', 'reviewer3@example.com',
            'publisher1@example.com', 'publisher2@example.com', 'publisher3@example.com',
            'copyeditor1@example.com', 'copyeditor2@example.com', 'copyeditor3@example.com',
            'proofreader1@example.com', 'proofreader2@example.com', 'proofreader3@example.com',
            'demo.superadmin@example.com', 'accepted.external.reviewer@example.com',
        ];
        $this->assertEquals(count($demoEmails), User::whereIn('email', $demoEmails)->count());

        // 4. Verify all 18 statuses exist
        $statuses = Article::pluck('status')->unique()->toArray();
        $expectedStatuses = [
            'draft', 'submitted', 'screening', 'in_transit', 'under_review', 'assigned_to_sub_editor',
            'reviewer_assigned', 'review_in_progress', 'revision_required',
            'minor_revision_required', 'major_revision_required', 'resubmitted',
            'accepted', 'rejected', 'copy_editing', 'proofreading',
            'ready_for_publication', 'published', 'withdrawn', 'archived'
        ];
        foreach ($expectedStatuses as $status) {
            $this->assertTrue(in_array($status, $statuses), "Missing status: " . $status);
            $this->assertGreaterThan(0, Article::where('status', $status)->count(), "No articles in status: " . $status);
        }

        // 5. Verify magazine-user assignments (Editors & Publishers)
        $this->assertEquals(12, DB::table('magazine_user')->count()); // 6 magazines * 2 users (1 editor, 1 publisher) = 12

        // 6. Sub Editor assignments exist
        $this->assertGreaterThan(0, DB::table('sub_editor_assignments')->count());

        // 7. Reviewer assignments exist
        $this->assertGreaterThan(0, DB::table('reviewer_assignments')->count());

        // 8. Production assignments exist
        $this->assertGreaterThan(0, DB::table('production_assignments')->count());

        // 9. Published articles have issue and publication metadata
        $publishedArticle = Article::where('status', 'published')->first();
        $this->assertNotNull($publishedArticle->magazine_issue_id);
        $this->assertNotNull($publishedArticle->doi);
        $this->assertNotNull($publishedArticle->page_start);
        $this->assertNotNull($publishedArticle->page_end);
        $this->assertNotNull($publishedArticle->published_at);
        $this->assertNotNull($publishedArticle->published_year);
        $this->assertNotNull($publishedArticle->published_month);
        $this->assertNotNull($publishedArticle->tracking_code);
        $this->assertNotNull($publishedArticle->academic_editor);
        $this->assertNotNull($publishedArticle->license_statement);
        $this->assertNotNull($publishedArticle->citation_text);
        $this->assertGreaterThan(0, ArticlePublicationSection::where('article_id', $publishedArticle->id)->count());

        // 10. Version snapshots exist
        $this->assertGreaterThan(0, DB::table('article_versions')->count());

        // 11. Test that Editors can see their assigned magazines/articles
        $editor = User::where('email', 'editor1@example.com')->first();
        $editorMagazines = $editor->magazines;
        $this->assertCount(2, $editorMagazines); // 2 magazines assigned to Editor 1 (out of 6 total magazines divided by 3 editors)

        // 12. Test date range constraint
        $articles = Article::orderBy('created_at')->get();
        $firstArticleDate = $articles->first()->created_at->format('Y-m-d');
        $lastArticleDate = $articles->last()->created_at->format('Y-m-d');
        $this->assertGreaterThanOrEqual('2015-01-23', $firstArticleDate);
        $this->assertLessThanOrEqual('2026-06-19', $lastArticleDate);

        // 13. Verify all seeded Sub-Editors are linked to at least one Editor (no orphans)
        $subEditors = User::whereHas('role', function($q) {
            $q->where('name', 'sub_editor');
        })->get();
        $this->assertNotEmpty($subEditors);
        foreach ($subEditors as $subEditor) {
            $linkCount = DB::table('editor_sub_editor')->where('sub_editor_id', $subEditor->id)->count();
            $this->assertGreaterThan(0, $linkCount, "Sub Editor {$subEditor->email} has no linked editors.");
        }

        // 14. Verify at least one shared Sub-Editor exists
        $sharedExists = false;
        foreach ($subEditors as $subEditor) {
            $linkCount = DB::table('editor_sub_editor')->where('sub_editor_id', $subEditor->id)->count();
            if ($linkCount > 1) {
                $sharedExists = true;
                break;
            }
        }
        $this->assertTrue($sharedExists, "No shared Sub-Editor exists in the seeded relationships.");

        // 15. Verify article Sub-Editor assignments belong to Sub-Editors linked to the article's assigned Editor
        $assignments = DB::table('sub_editor_assignments')->get();
        $this->assertNotEmpty($assignments);
        foreach ($assignments as $assignment) {
            $article = Article::find($assignment->article_id);
            $this->assertNotNull($article);
            
            // The article's magazine has assigned editors
            $magazineEditor = DB::table('magazine_user')
                ->where('magazine_id', $article->magazine_id)
                ->where('role', 'editor')
                ->first();
            $this->assertNotNull($magazineEditor, "No Editor assigned to magazine ID {$article->magazine_id} for article ID {$article->id}");
            
            // Check if there is a relationship between this editor and the assigned sub-editor
            $linkExists = DB::table('editor_sub_editor')
                ->where('editor_id', $magazineEditor->user_id)
                ->where('sub_editor_id', $assignment->sub_editor_id)
                ->exists();
            $this->assertTrue($linkExists, "Article {$article->id} (magazine {$article->magazine_id}) is assigned to Sub Editor {$assignment->sub_editor_id}, but that Sub Editor is not linked to the magazine's Editor {$magazineEditor->user_id}.");
        }

        $trackingCodes = Article::pluck('tracking_code')->filter();
        $this->assertCount(Article::count(), $trackingCodes);
        $this->assertSame($trackingCodes->count(), $trackingCodes->unique()->count());
        $this->assertMatchesRegularExpression('/^SN-\d{4}-\d{6}$/', $trackingCodes->first());

        $this->assertGreaterThan(0, ArticleReviewerPreference::where('type', 'suggested')->count());
        $this->assertGreaterThan(0, ArticleReviewerPreference::where('type', 'opposed')->count());
        $this->assertTrue(Article::whereHas('reviewerPreferences', fn ($query) => $query->where('type', 'suggested'))
            ->whereDoesntHave('reviewerPreferences', fn ($query) => $query->where('type', 'opposed'))
            ->exists());
        $this->assertTrue(Article::whereHas('reviewerPreferences', fn ($query) => $query->where('type', 'opposed'))
            ->whereDoesntHave('reviewerPreferences', fn ($query) => $query->where('type', 'suggested'))
            ->exists());
        $this->assertTrue(Article::whereHas('reviewerPreferences', fn ($query) => $query->where('type', 'suggested'))
            ->whereHas('reviewerPreferences', fn ($query) => $query->where('type', 'opposed'))
            ->exists());
        $this->assertTrue(Article::whereDoesntHave('reviewerPreferences')->exists());

        $opposedEmails = ArticleReviewerPreference::where('type', 'opposed')->pluck('email')->all();
        $assignedEmails = ReviewerAssignment::query()
            ->where(function ($query) {
                $query->whereNotNull('reviewer_id')->orWhereNotNull('invitee_email');
            })
            ->pluck('invitee_email')
            ->filter()
            ->all();
        $this->assertEmpty(array_intersect($opposedEmails, $assignedEmails));

        $this->assertDatabaseHas('reviewer_assignments', [
            'invitee_email' => 'pending.external.reviewer@example.com',
            'reviewer_id' => null,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('reviewer_assignments', [
            'invitee_email' => 'accepted.external.reviewer@example.com',
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('reviewer_assignments', [
            'invitee_email' => 'declined.external.reviewer@example.com',
            'reviewer_id' => null,
            'status' => 'declined',
        ]);

        $questionnaire = ReviewQuestionnaire::with('versions.questions.options')->where('name', 'Academic Demo Reviewer Questionnaire')->first();
        $this->assertNotNull($questionnaire);
        $this->assertTrue((bool) $questionnaire->is_active);
        $activeVersion = $questionnaire->versions->firstWhere('is_active', true);
        $this->assertNotNull($activeVersion);
        $this->assertEqualsCanonicalizing(
            ['radio', 'checkbox', 'dropdown', 'single_line', 'textarea'],
            $activeVersion->questions->pluck('response_type')->all()
        );
        $this->assertGreaterThanOrEqual(5, $activeVersion->questions->flatMap->options->count());
        $this->assertGreaterThan(0, ReviewQuestionnaireInstance::count());
        $this->assertGreaterThan(0, ReviewQuestionResponse::count());
        ReviewQuestionResponse::with('question.options')->get()->each(function ($response) {
            $this->assertNotNull($response->question);
        });

        $this->assertGreaterThan(0, DB::table('article_assets')->where('asset_type', 'image')->where('mime_type', 'like', 'image/%')->count());
        $this->assertSame(0, DB::table('article_assets')->where('original_filename', 'like', '%.zip')->count());

        $this->assertSame(0, $this->demoTerminologyMatches());
    }

    public function test_academic_workflow_demo_seeder_is_idempotent(): void
    {
        $this->seed(AcademicWorkflowDemoSeeder::class);
        $counts = $this->demoCounts();

        $this->seed(AcademicWorkflowDemoSeeder::class);

        $this->assertSame($counts, $this->demoCounts());
        $this->assertSame(Article::count(), Article::pluck('tracking_code')->unique()->count());
    }

    private function demoCounts(): array
    {
        return [
            'users' => User::count(),
            'magazines' => Magazine::count(),
            'articles' => Article::count(),
            'preferences' => ArticleReviewerPreference::count(),
            'assignments' => ReviewerAssignment::count(),
            'questionnaires' => ReviewQuestionnaire::count(),
            'instances' => ReviewQuestionnaireInstance::count(),
            'responses' => ReviewQuestionResponse::count(),
            'sections' => ArticlePublicationSection::count(),
        ];
    }

    private function demoTerminologyMatches(): int
    {
        $titleTerm = 'J' . 'ournal';
        $bodyTerm = 'j' . 'ournal';
        $articleMatches = Article::query()
            ->where('title', 'like', "%{$titleTerm}%")
            ->orWhere('abstract', 'like', "%{$bodyTerm}%")
            ->orWhere('full_text', 'like', "%{$bodyTerm}%")
            ->count();
        $magazineMatches = Magazine::query()
            ->where('title', 'like', "%{$titleTerm}%")
            ->orWhere('description', 'like', "%{$bodyTerm}%")
            ->orWhere('about_text', 'like', "%{$bodyTerm}%")
            ->count();

        return $articleMatches + $magazineMatches;
    }
}
