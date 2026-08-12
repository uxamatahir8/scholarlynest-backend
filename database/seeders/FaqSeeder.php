<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

class FaqSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faqs = [
            [
                'question' => 'What is ScholarlyNest?',
                'answer' => 'ScholarlyNest is a trusted, open-access platform where researchers, institutions, and readers collaborate on scientific discovery and knowledge sharing.',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'question' => 'How secure is my data?',
                'answer' => 'We maintain complete data integrity. All information is secured with enterprise-grade encryption and access control protocols.',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'question' => 'Is there a charge for open-access publishing?',
                'answer' => 'No. In alignment with our Open Science Pledge, all publications are funded via institutional grants and community support. Access is free forever.',
                'sort_order' => 3,
                'is_active' => true,
            ]
        ];

        foreach ($faqs as $faq) {
            Faq::create($faq);
        }
    }
}
