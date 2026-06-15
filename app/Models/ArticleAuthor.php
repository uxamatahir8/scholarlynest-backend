<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAuthor extends Model
{
    protected $table = 'article_author';

    protected $fillable = [
        'article_id',
        'user_id',
        'co_author_name',
        'co_author_email',
        'affiliation',
        'department',
        'country',
        'orcid',
        'author_order',
        'is_owner',
        'is_corresponding',
        'contribution_statement',
        'can_edit',
        'account_provisioned',
        'university_name',
    ];

    protected $casts = [
        'can_edit' => 'boolean',
        'account_provisioned' => 'boolean',
        'is_owner' => 'boolean',
        'is_corresponding' => 'boolean',
        'author_order' => 'integer',
    ];

    /**
     * Get the article this co-author is attached to.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Get the user account linked to this co-author.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
