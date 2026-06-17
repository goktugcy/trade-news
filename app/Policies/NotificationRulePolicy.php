<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NotificationRule;
use App\Models\User;

class NotificationRulePolicy
{
    public function view(User $user, NotificationRule $rule): bool
    {
        return $rule->user_id === $user->id;
    }

    public function update(User $user, NotificationRule $rule): bool
    {
        return $rule->user_id === $user->id;
    }

    public function delete(User $user, NotificationRule $rule): bool
    {
        return $rule->user_id === $user->id;
    }
}
