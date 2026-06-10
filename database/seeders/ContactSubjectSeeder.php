<?php

namespace Database\Seeders;

use App\Models\ContactSubject;
use Illuminate\Database\Seeder;

class ContactSubjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $subjects = [
            ['label' => 'General Inquiry', 'value' => 'general', 'sort_order' => 0],
            ['label' => 'Manuscript Submission Question', 'value' => 'manuscript', 'sort_order' => 1],
            ['label' => 'Peer Review Process', 'value' => 'review', 'sort_order' => 2],
            ['label' => 'Institutional Partnership', 'value' => 'partnership', 'sort_order' => 3],
            ['label' => 'Abstract Indexing Status', 'value' => 'indexing', 'sort_order' => 4],
        ];

        foreach ($subjects as $s) {
            ContactSubject::updateOrCreate(
                ['value' => $s['value']],
                $s
            );
        }
    }
}
