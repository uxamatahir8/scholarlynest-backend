<?php

namespace App\Events;

use App\Models\Article;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArticleSubmitted
{
    use Dispatchable, SerializesModels;

    public Article $article;
    public array $coAuthorsData;

    /**
     * Create a new event instance.
     */
    public function __construct(Article $article, array $coAuthorsData)
    {
        $this->article = $article;
        $this->coAuthorsData = $coAuthorsData;
    }
}
