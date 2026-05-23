<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\Auditable;

class Setting extends Model
{
    use HasFactory, Auditable;

    protected $table = 'settings';

    protected $fillable = [
        'key',
        'value',
    ];
}
