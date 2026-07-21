<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id', 'category', 'in_app_enabled', 'email_mode', 'digest_frequency',
        'quiet_hours_start', 'quiet_hours_end', 'timezone',
    ];

    protected $casts = [
        'in_app_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
