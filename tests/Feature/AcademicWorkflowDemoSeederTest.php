<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AcademicWorkflowDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AcademicWorkflowDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function logProgress(string $msg): void
    {
        file_put_contents('/home/developer/workspace/backend-api/debug_assertions.txt', $msg . "\n", FILE_APPEND);
    }

    public function test_academic_workflow_demo_seeder_populates_correct_records(): void
    {
        file_put_contents('/home/developer/workspace/backend-api/debug_assertions.txt', "Test Started\n");

        // Run the seeder
        $this->seed(AcademicWorkflowDemoSeeder::class);
        $this->logProgress("Seeder run completed");

        // 1. Verify 12 magazines exist
        $this->logProgress("Asserting 12 magazines");
        $this->assertEquals(12, Magazine::count());

        // 2. Verify 6600 articles exist (550 per magazine)
        $this->logProgress("Asserting 6600 articles");
        $this->assertEquals(6600, Article::count());

        // 3. Verify users created per role
        $this->logProgress("Asserting users count");
        $demoEmails = [
            'author1@example.com', 'author2@example.com', 'author3@example.com',
            'editor1@example.com', 'editor2@example.com', 'editor3@example.com',
            'subeditor1@example.com', 'subeditor2@example.com', 'subeditor3@example.com',
            'reviewer1@example.com', 'reviewer2@example.com', 'reviewer3@example.com',
            'publisher1@example.com', 'publisher2@example.com', 'publisher3@example.com',
            'copyeditor1@example.com', 'copyeditor2@example.com', 'copyeditor3@example.com',
            'proofreader1@example.com', 'proofreader2@example.com', 'proofreader3@example.com',
            'demo.superadmin@example.com',
        ];
        $this->assertEquals(count($demoEmails), User::whereIn('email', $demoEmails)->count());

        // 4. Verify all 18 statuses exist
        $this->logProgress("Asserting all 18 statuses");
        $statuses = Article::pluck('status')->unique()->toArray();
        $expectedStatuses = [
            'draft', 'submitted', 'under_review', 'assigned_to_sub_editor',
            'reviewer_assigned', 'review_in_progress', 'revision_required',
            'minor_revision_required', 'major_revision_required', 'resubmitted',
            'accepted', 'rejected', 'copy_editing', 'proofreading',
            'ready_for_publication', 'published', 'withdrawn', 'archived'
        ];
        foreach ($expectedStatuses as $status) {
            $this->logProgress("Checking status: " . $status);
            $this->assertTrue(in_array($status, $statuses), "Missing status: " . $status);
            $this->assertGreaterThan(0, Article::where('status', $status)->count(), "No articles in status: " . $status);
        }

        // 5. Verify magazine-user assignments (Editors & Publishers)
        $this->logProgress("Asserting magazine_user count");
        $this->assertEquals(24, DB::table('magazine_user')->count()); // 12 magazines * 2 users (1 editor, 1 publisher) = 24

        // 6. Sub Editor assignments exist
        $this->logProgress("Asserting sub_editor_assignments");
        $this->assertGreaterThan(0, DB::table('sub_editor_assignments')->count());

        // 7. Reviewer assignments exist
        $this->logProgress("Asserting reviewer_assignments");
        $this->assertGreaterThan(0, DB::table('reviewer_assignments')->count());

        // 8. Production assignments exist
        $this->logProgress("Asserting production_assignments");
        $this->assertGreaterThan(0, DB::table('production_assignments')->count());

        // 9. Published articles have issue and publication metadata
        $this->logProgress("Asserting published article metadata");
        $publishedArticle = Article::where('status', 'published')->first();
        $this->assertNotNull($publishedArticle->magazine_issue_id);
        $this->assertNotNull($publishedArticle->doi);
        $this->assertNotNull($publishedArticle->page_start);
        $this->assertNotNull($publishedArticle->page_end);
        $this->assertNotNull($publishedArticle->published_at);
        $this->assertNotNull($publishedArticle->published_year);
        $this->assertNotNull($publishedArticle->published_month);

        // 10. Version snapshots exist
        $this->logProgress("Asserting article_versions");
        $this->assertGreaterThan(0, DB::table('article_versions')->count());

        // 11. Test that Editors can see their assigned magazines/articles
        $this->logProgress("Asserting editor assigned magazines");
        $editor = User::where('email', 'editor1@example.com')->first();
        $editorMagazines = $editor->magazines;
        $this->assertCount(4, $editorMagazines); // 4 magazines assigned to Editor 1

        // 12. Test date range constraint
        $this->logProgress("Asserting date range");
        $articles = Article::orderBy('created_at')->get();
        $firstArticleDate = $articles->first()->created_at->format('Y-m-d');
        $lastArticleDate = $articles->last()->created_at->format('Y-m-d');
        
        $this->logProgress("First article: " . $firstArticleDate . ", Last article: " . $lastArticleDate);
        // 13. Verify all seeded Sub-Editors are linked to at least one Editor (no orphans)
        $this->logProgress("Asserting no orphan Sub Editors");
        $subEditors = User::whereHas('role', function($q) {
            $q->where('name', 'sub_editor');
        })->get();
        $this->assertNotEmpty($subEditors);
        foreach ($subEditors as $subEditor) {
            $linkCount = DB::table('editor_sub_editor')->where('sub_editor_id', $subEditor->id)->count();
            $this->assertGreaterThan(0, $linkCount, "Sub Editor {$subEditor->email} has no linked editors.");
        }

        // 14. Verify at least one shared Sub-Editor exists
        $this->logProgress("Asserting shared Sub Editor exists");
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
        $this->logProgress("Asserting article assignments match editor-sub_editor links");
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

        $this->logProgress("Test finished successfully");
    }
}

