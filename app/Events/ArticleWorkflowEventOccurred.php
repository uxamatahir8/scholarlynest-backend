<?php

namespace App\Events;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ArticleWorkflowEventOccurred
{
    use Dispatchable, SerializesModels;

    public string $notificationEventUuid;

    public string $notificationOccurredAt;

    public ?int $notificationEventId = null;

    public function __construct(
        public Article $article,
        public string $event,
        public ?User $actor = null,
        public array $payload = []
    ) {
        $this->notificationEventUuid = (string) Str::uuid();
        $this->notificationOccurredAt = now()->toISOString();
    }
}
