<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlugRedirect extends Model
{
    protected $fillable = ['scope_key', 'entity_type', 'entity_id', 'old_slug', 'new_slug', 'parent_type', 'parent_id'];
}
