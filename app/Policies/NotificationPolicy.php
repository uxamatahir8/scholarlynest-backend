<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserNotification;

class NotificationPolicy
{
    public function view(User $user, UserNotification $notification): bool
    {
        return $notification->in_app_visible
            && (int) $notification->recipient_user_id === (int) $user->id;
    }

    public function update(User $user, UserNotification $notification): bool
    {
        return $this->view($user, $notification);
    }
}
