<?php

namespace Database\Seeders;

use App\Models\ReviewQuestion;
use App\Models\ReviewQuestionOption;
use App\Models\ReviewQuestionnaire;
use App\Models\ReviewQuestionnaireVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReviewerEvaluationQuestionnaireSeeder extends Seeder
{
    public const TEMPLATE_NAME = 'Default Reviewer Evaluation Questionnaire';

    public function run(): void
    {
        DB::transaction(function (): void {
            ReviewQuestionnaire::query()->update(['is_active' => false]);

            $questionnaire = ReviewQuestionnaire::updateOrCreate(
                ['name' => self::TEMPLATE_NAME],
                ['is_active' => true]
            );

            $questionnaire->versions()->update(['is_active' => false]);
            $version = ReviewQuestionnaireVersion::updateOrCreate(
                ['review_questionnaire_id' => $questionnaire->id, 'version_number' => 1],
                ['is_active' => true, 'published_at' => now()]
            );

            foreach ($this->questions() as $index => $definition) {
                $question = ReviewQuestion::updateOrCreate(
                    ['review_questionnaire_version_id' => $version->id, 'sort_order' => $index + 1],
                    [
                        'prompt' => $definition['prompt'],
                        'comment_helper' => $definition['comment_helper'] ?? null,
                        'response_type' => $definition['response_type'],
                        'is_required' => $definition['is_required'],
                    ]
                );

                foreach ($definition['options'] ?? [] as $optionIndex => $option) {
                    ReviewQuestionOption::updateOrCreate(
                        ['review_question_id' => $question->id, 'sort_order' => $optionIndex + 1],
                        ['label' => $option['label'], 'value' => $option['value']]
                    );
                }
            }
        });
    }

    public function questions(): array
    {
        $evaluationQuestions = [
            ['Is the title clear and adequate to the purpose of the study?', 'If No, suggest changes.'],
            ['Does the abstract clearly present objectives, methods, results, and conclusions?', 'If No, suggest modification.'],
            ['Are the key words adequate?', 'If No, suggest modification.'],
            ['Has the subject been introduced properly with recent reference support?', 'If No, suggest modification.'],
            ['Are scientific methods adequately used?', 'If No, suggest modification.'],
            ['Is the volume of the paper adequate and reduction is not necessary?', 'If No, suggest modification or reduction.'],
            ['Are the results clearly presented?', 'If No, suggest modification.'],
            ['Is the discussion logically derived from the data presented and properly supported with published literature?', 'If No, suggest modification.'],
            ['Are the conclusions properly drawn based on the results?', 'If No, suggest modification.'],
            ['Are the references appropriate?', 'If No, suggest modification.'],
            ['Are supplements such as tables, charts, pictures, and drawings necessary and clear?', 'If No, suggest modification.'],
        ];

        return [
            [
                'prompt' => 'Manuscript Category',
                'response_type' => 'radio',
                'is_required' => true,
                'options' => [
                    ['label' => 'Original Research Paper', 'value' => 'original_research_paper'],
                    ['label' => 'Short Communication', 'value' => 'short_communication'],
                    ['label' => 'Case Report / Clinical Article', 'value' => 'case_report_clinical_article'],
                ],
            ],
            ...array_map(fn (array $item) => [
                'prompt' => $item[0],
                'comment_helper' => $item[1],
                'response_type' => 'radio',
                'is_required' => true,
                'options' => [
                    ['label' => 'Yes', 'value' => 'yes'],
                    ['label' => 'No', 'value' => 'no'],
                ],
            ], $evaluationQuestions),
            [
                'prompt' => 'Reviewer comments, if any',
                'response_type' => 'textarea',
                'is_required' => false,
            ],
            [
                'prompt' => 'Final Decision',
                'response_type' => 'radio',
                'is_required' => true,
                'options' => [
                    ['label' => 'This manuscript is acceptable in its present form', 'value' => 'accept'],
                    ['label' => 'This manuscript will be reconsidered after minor revision', 'value' => 'minor_revision'],
                    ['label' => 'This manuscript will be reconsidered after moderate revision', 'value' => 'moderate_revision'],
                    ['label' => 'This manuscript will be reconsidered after major revision', 'value' => 'major_revision'],
                    ['label' => 'This manuscript is not acceptable for publication', 'value' => 'reject'],
                ],
            ],
        ];
    }
}
