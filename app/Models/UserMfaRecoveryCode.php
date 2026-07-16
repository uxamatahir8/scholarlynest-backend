<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserMfaRecoveryCode extends Model
{
    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
