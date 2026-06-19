<?php

namespace App\Events;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArticleWorkflowEventOccurred
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Article $article,
        public string $event,
        public ?User $actor = null,
        public array $payload = []
    ) {
    }
}
