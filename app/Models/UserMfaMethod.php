<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMfaMethod extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret_encrypted', 'pending_secret_encrypted', 'metadata_json'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_verified' => 'boolean',
            'metadata_json' => 'array',
            'pending_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
