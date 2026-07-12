<?php

namespace Tests\Feature;

use App\Models\ReviewQuestionnaire;
use Database\Seeders\ReviewerEvaluationQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewerEvaluationQuestionnaireSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_complete_active_reviewer_evaluation_questionnaire(): void
    {
        $this->seed(ReviewerEvaluationQuestionnaireSeeder::class);

        $questionnaire = ReviewQuestionnaire::with('versions.questions.options')
            ->where('name', ReviewerEvaluationQuestionnaireSeeder::TEMPLATE_NAME)
            ->firstOrFail();
        $version = $questionnaire->versions->firstWhere('is_active', true);

        $this->assertTrue($questionnaire->is_active);
        $this->assertNotNull($version);
        $this->assertCount(14, $version->questions);
        $this->assertSame('Manuscript Category', $version->questions[0]->prompt);
        $this->assertTrue($version->questions[0]->is_required);
        $this->assertSame('radio', $version->questions[0]->response_type);
        $this->assertSame([
            'Original Research Paper',
            'Short Communication',
            'Case Report / Clinical Article',
        ], $version->questions[0]->options->pluck('label')->all());

        $evaluation = $version->questions->slice(1, 11);
        $this->assertCount(11, $evaluation);
        $evaluation->each(function ($question): void {
            $this->assertSame('radio', $question->response_type);
            $this->assertTrue($question->is_required);
            $this->assertNotEmpty($question->comment_helper);
            $this->assertSame(['yes', 'no'], $question->options->pluck('value')->all());
        });

        $this->assertSame('Reviewer comments, if any', $version->questions[12]->prompt);
        $this->assertSame('textarea', $version->questions[12]->response_type);
        $this->assertFalse($version->questions[12]->is_required);
        $this->assertSame('Final Decision', $version->questions[13]->prompt);
        $this->assertTrue($version->questions[13]->is_required);
        $this->assertSame([
            'accept',
            'minor_revision',
            'moderate_revision',
            'major_revision',
            'reject',
        ], $version->questions[13]->options->pluck('value')->all());
    }

    public function test_seeder_is_idempotent_without_duplicate_questions_or_options(): void
    {
        $this->seed(ReviewerEvaluationQuestionnaireSeeder::class);
        $counts = [
            'questionnaires' => \DB::table('review_questionnaires')->count(),
            'versions' => \DB::table('review_questionnaire_versions')->count(),
            'questions' => \DB::table('review_questions')->count(),
            'options' => \DB::table('review_question_options')->count(),
        ];

        $this->seed(ReviewerEvaluationQuestionnaireSeeder::class);

        $this->assertSame($counts, [
            'questionnaires' => \DB::table('review_questionnaires')->count(),
            'versions' => \DB::table('review_questionnaire_versions')->count(),
            'questions' => \DB::table('review_questions')->count(),
            'options' => \DB::table('review_question_options')->count(),
        ]);
    }
}
