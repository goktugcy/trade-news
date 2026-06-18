<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Notification;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers a single queued notification to the user's Telegram chat and records
 * the delivery outcome on the notification log row.
 */
class SendTelegramNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 20;

    public int $timeout = 30;

    public function __construct(public int $notificationId) {}

    public function handle(TelegramBotService $telegram): void
    {
        /** @var Notification|null $notification */
        $notification = Notification::query()
            ->with(['user.telegramIntegration', 'newsItem.stocks', 'newsItem.source'])
            ->find($this->notificationId);

        if ($notification === null || $notification->status === Notification::STATUS_SENT) {
            return;
        }

        $integration = $notification->user?->telegramIntegration;

        if ($integration === null || ! $integration->isActive()) {
            $notification->update([
                'status' => Notification::STATUS_FAILED,
                'error' => 'Telegram not connected or alerts disabled.',
            ]);

            return;
        }

        $body = $notification->newsItem
            ? $telegram->formatNewsAlert($notification->newsItem, $notification->user->timezone)
            : ($notification->body ?: $notification->title);

        $sent = $telegram->sendMessage($integration->chat_id, $body);

        $notification->update([
            'body' => $body,
            'status' => $sent ? Notification::STATUS_SENT : Notification::STATUS_FAILED,
            'sent_at' => $sent ? now() : null,
            'error' => $sent ? null : 'Telegram API rejected the message.',
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Notification::query()->where('id', $this->notificationId)->update([
            'status' => Notification::STATUS_FAILED,
            'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
