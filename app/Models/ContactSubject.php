<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactSubject extends Model
{
    protected $fillable = [
        'label',
        'value',
        'sort_order',
    ];
}
