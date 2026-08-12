<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaChallenge extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'email_code_hash'];

    protected function casts(): array
    {
        return [
            'required_methods' => 'array',
            'verified_methods' => 'array',
            'recovery_code_allowed' => 'boolean',
            'expires_at' => 'datetime',
            'email_code_sent_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
