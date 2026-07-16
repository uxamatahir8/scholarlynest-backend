<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMfaSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'last_verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
