<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Jobs\SendTelegramNotificationJob;
use App\Models\NewsItem;
use App\Models\Notification;
use App\Models\NotificationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns due notification rules into per-user, de-duplicated alerts.
 *
 * News is fetched/stored centrally; this is the *only* place that decides which
 * stored item reaches which user, based on their watchlist + rule filters.
 */
class NotificationDispatcher
{
    /** Cap alerts per rule per run so a backlog never floods a user. */
    private const MAX_PER_RUN = 10;

    /**
     * Process every active rule that is due at the given moment.
     *
     * @return int number of alerts queued
     */
    public function dispatchDue(\DateTimeInterface $moment): int
    {
        $queued = 0;

        NotificationRule::query()
            ->active()
            ->dueAt($moment)
            ->with('user.telegramIntegration')
            ->chunkById(200, function ($rules) use (&$queued): void {
                foreach ($rules as $rule) {
                    $queued += $this->processRule($rule);
                }
            });

        return $queued;
    }

    /**
     * @return int number of alerts queued for this rule
     */
    public function processRule(NotificationRule $rule): int
    {
        $user = $rule->user;
        $integration = $user?->telegramIntegration;

        // No point selecting news if there's nowhere to deliver it.
        if ($user === null || $integration === null || ! $integration->isActive()) {
            $rule->forceFill(['last_dispatched_at' => now()])->save();

            return 0;
        }

        $watchlistStockIds = $user->watchlist()
            ->where('alerts_enabled', true)
            ->pluck('stock_id');

        if ($rule->only_watchlist && $watchlistStockIds->isEmpty()) {
            $rule->forceFill(['last_dispatched_at' => now()])->save();

            return 0;
        }

        $since = $rule->last_dispatched_at ?? now()->subDay();

        $items = NewsItem::query()
            ->where('is_matched', true)
            ->where('published_at', '>', $since)
            ->where('importance_score', '>=', $rule->min_importance)
            ->when($rule->markets, fn (Builder $q) => $q->whereIn('market', $rule->markets))
            ->when($rule->sentiments, fn (Builder $q) => $q->whereIn('sentiment', $rule->sentiments))
            ->when(
                $rule->only_watchlist,
                fn (Builder $q) => $q->whereHas('stocks', fn (Builder $s) => $s->whereIn('stocks.id', $watchlistStockIds)),
            )
            // Skip items this user was already alerted about on this channel.
            ->whereNotExists(function ($q) use ($user): void {
                $q->select(DB::raw(1))
                    ->from('app_notifications')
                    ->whereColumn('app_notifications.news_item_id', 'news_items.id')
                    ->where('app_notifications.user_id', $user->id)
                    ->where('app_notifications.channel', 'telegram');
            })
            ->with(['stocks:id,symbol', 'source:id,name'])
            ->orderByDesc('importance_score')
            ->orderByDesc('published_at')
            ->limit(self::MAX_PER_RUN)
            ->get();

        $queued = 0;

        foreach ($items as $item) {
            $notification = Notification::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'news_item_id' => $item->id,
                    'channel' => 'telegram',
                ],
                [
                    'notification_rule_id' => $rule->id,
                    'status' => Notification::STATUS_QUEUED,
                    'title' => $item->title,
                    'body' => null,
                ],
            );

            if ($notification->wasRecentlyCreated) {
                SendTelegramNotificationJob::dispatch($notification->id);
                $queued++;
            }
        }

        $rule->forceFill(['last_dispatched_at' => now()])->save();

        return $queued;
    }
}
