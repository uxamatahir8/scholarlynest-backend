<?php

namespace Tests\Feature;

use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_faq_endpoint_returns_only_active_allow_listed_fields(): void
    {
        Faq::create([
            'question' => 'Inactive question?',
            'answer' => 'This should not be public.',
            'sort_order' => 1,
            'is_active' => false,
        ]);

        $active = Faq::create([
            'question' => 'How can I submit my research?',
            'answer' => 'Use the public submission path.',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/public/faqs');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.question', 'How can I submit my research?')
            ->assertJsonPath('data.0.answer', 'Use the public submission path.')
            ->assertJsonPath('data.0.sort_order', 2)
            ->assertJsonMissing(['is_active' => true])
            ->assertJsonMissing(['created_at' => $active->created_at])
            ->assertJsonMissing(['updated_at' => $active->updated_at])
            ->assertJsonMissing(['question' => 'Inactive question?']);
    }
}
