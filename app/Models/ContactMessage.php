<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = [
        'name',
        'email',
        'affiliation',
        'subject',
        'message',
        'status',
    ];

    /**
     * Get the replies associated with the contact message.
     */
    public function replies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ContactReply::class, 'contact_message_id')->orderBy('created_at', 'asc');
    }
}
