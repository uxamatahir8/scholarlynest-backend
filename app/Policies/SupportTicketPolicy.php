<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\User;

class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $this->canManage($user) || (int) $ticket->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function reply(User $user, SupportTicket $ticket): bool
    {
        if ($this->canManage($user)) {
            return true;
        }

        return (int) $ticket->user_id === (int) $user->id && $ticket->status !== 'closed';
    }

    public function updateStatus(User $user, SupportTicket $ticket): bool
    {
        return $this->canManage($user);
    }

    public function viewActivity(User $user, SupportTicket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function downloadAttachment(User $user, SupportTicketAttachment $attachment): bool
    {
        $ticket = $attachment->ticket ?: $attachment->message?->ticket;

        return $ticket && $this->view($user, $ticket);
    }

    public function canManage(User $user): bool
    {
        return $user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasPermission('support_ticket_management');
    }
}
