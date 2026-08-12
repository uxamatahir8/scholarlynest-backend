<?php

namespace App\Events;

use App\Models\Article;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ArticleSubmitted
{
    use Dispatchable, SerializesModels;

    public Article $article;

    public array $coAuthorsData;

    public string $notificationEventUuid;

    public string $notificationOccurredAt;

    public ?int $notificationEventId = null;

    /**
     * Create a new event instance.
     */
    public function __construct(Article $article, array $coAuthorsData)
    {
        $this->article = $article;
        $this->coAuthorsData = $coAuthorsData;
        $this->notificationEventUuid = (string) Str::uuid();
        $this->notificationOccurredAt = now()->toISOString();
    }
}
