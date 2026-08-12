<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Faq extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'question',
        'answer',
        'sort_order',
        'is_active',
    ];
}
