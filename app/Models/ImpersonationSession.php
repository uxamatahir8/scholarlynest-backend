<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class ImpersonationSession extends Model
{
    protected $fillable = [
        'original_super_admin_id',
        'impersonated_user_id',
        'impersonation_token_id',
        'started_at',
        'stopped_at',
        'expires_at',
        'status',
        'started_ip',
        'started_user_agent',
        'stopped_ip',
        'stopped_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the original super admin user.
     */
    public function originalSuperAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_super_admin_id');
    }

    /**
     * Get the impersonated user.
     */
    public function impersonatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    /**
     * Get the temporary personal access token used.
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'impersonation_token_id');
    }
}
