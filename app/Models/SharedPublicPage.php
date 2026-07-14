<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SharedPublicPage extends Model
{
    public const SCOPE_ALL_MAGAZINES = 'all_magazines';

    public const SCOPE_SELECTED_MAGAZINES = 'selected_magazines';

    public const SCOPE_ALL_JOURNALS = 'all_journals';

    public const SCOPE_SELECTED_JOURNALS = 'selected_journals';

    public const SCOPE_ALL_PUBLICATIONS = 'all_publications';

    public const SCOPE_CUSTOM = 'custom_selection';

    public const SCOPES = [
        self::SCOPE_ALL_MAGAZINES,
        self::SCOPE_SELECTED_MAGAZINES,
        self::SCOPE_ALL_JOURNALS,
        self::SCOPE_SELECTED_JOURNALS,
        self::SCOPE_ALL_PUBLICATIONS,
        self::SCOPE_CUSTOM,
    ];

    protected $fillable = [
        'title', 'slug', 'content', 'status', 'target_scope', 'show_in_navigation',
        'sort_order', 'seo_title', 'seo_description', 'created_by', 'updated_by',
    ];

    protected $casts = ['show_in_navigation' => 'boolean'];

    public function targets(): HasMany
    {
        return $this->hasMany(SharedPublicPageTarget::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleFor(Builder $query, Magazine $publication): Builder
    {
        $allScope = $publication->isJournal() ? self::SCOPE_ALL_JOURNALS : self::SCOPE_ALL_MAGAZINES;

        return $query->where('status', 'active')->where(function (Builder $scopeQuery) use ($publication, $allScope) {
            $scopeQuery->whereIn('target_scope', [$allScope, self::SCOPE_ALL_PUBLICATIONS])
                ->orWhere(function (Builder $targetedQuery) use ($publication) {
                    $targetedQuery->whereIn('target_scope', [
                        self::SCOPE_SELECTED_MAGAZINES,
                        self::SCOPE_SELECTED_JOURNALS,
                        self::SCOPE_CUSTOM,
                    ])->whereHas('targets', fn (Builder $targetQuery) => $targetQuery
                        ->where('publication_id', $publication->id)
                        ->where('publication_type', $publication->publication_type));
                });
        });
    }
}
