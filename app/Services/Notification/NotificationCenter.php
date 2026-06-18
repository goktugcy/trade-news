<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\NotificationCategory;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;

/**
 * Single entry point for creating in-app (platform) notifications, with an
 * optional Telegram side-channel. Used by alerts (Phase 2), the provider state
 * machine (Phase 3) and sync jobs (Phase 4).
 */
class NotificationCenter
{
    /**
     * Create an in-app notification for a user. When $telegram is true and the
     * user has an active Telegram integration, also queue a Telegram message.
     *
     * @param  array<string, mixed>  $data
     */
    public function toUser(
        User $user,
        NotificationCategory $category,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        ?string $actionUrl = null,
        bool $telegram = false,
    ): UserNotification {
        $notification = $user->userNotifications()->create([
            'category' => $category,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'action_url' => $actionUrl,
            'created_at' => now(),
        ]);

        if ($telegram && $user->telegramIntegration?->isActive()) {
            $log = Notification::create([
                'user_id' => $user->id,
                'channel' => 'telegram',
                'status' => Notification::STATUS_QUEUED,
                'title' => $title,
                'body' => trim($title."\n\n".(string) $body),
            ]);

            SendTelegramNotificationJob::dispatch($log->id);
        }

        return $notification;
    }

    /**
     * Fan a notification out to every administrator.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, UserNotification>
     */
    public function toAdmins(
        NotificationCategory $category,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        ?string $actionUrl = null,
    ): Collection {
        return User::query()
            ->where('is_admin', true)
            ->get()
            ->map(fn (User $admin): UserNotification => $this->toUser(
                $admin, $category, $type, $title, $body, $data, $actionUrl,
            ));
    }
}
