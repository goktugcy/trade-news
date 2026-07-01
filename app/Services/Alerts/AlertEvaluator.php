<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\AlertType;
use App\Enums\NotificationCategory;
use App\Models\Stock;
use App\Models\StockAlert;
use App\Services\MarketData\MarketDataIngestor;
use App\Services\Notification\NotificationCenter;
use Carbon\CarbonImmutable;

/**
 * Evaluates user-defined stock alerts against the latest cached quote (price /
 * volume / % change) or freshly-matched news, fires an in-app notification
 * (+ optional Telegram), and honours each alert's cooldown.
 */
class AlertEvaluator
{
    private const DEFAULT_IMPORTANCE_FLOOR = 50;

    public function __construct(
        private readonly NotificationCenter $notifications,
    ) {}

    /**
     * @return int number of alerts fired
     */
    public function evaluateAll(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $fired = 0;

        StockAlert::query()
            ->active()
            ->with(['stock', 'user'])
            ->chunkById(200, function ($alerts) use (&$fired, $now): void {
                foreach ($alerts as $alert) {
                    if ($alert->stock === null || $alert->user === null || $alert->inCooldown($now)) {
                        continue;
                    }

                    $message = $this->check($alert, $now);

                    if ($message !== null) {
                        $this->fire($alert, $message, $now);
                        $fired++;
                    }
                }
            });

        return $fired;
    }

    /**
     * Returns the alert body when the condition is met, otherwise null.
     */
    private function check(StockAlert $alert, CarbonImmutable $now): ?string
    {
        return $alert->type->isNewsType()
            ? $this->checkNews($alert, $now)
            : $this->checkPrice($alert);
    }

    private function checkPrice(StockAlert $alert): ?string
    {
        $stock = $alert->stock;
        $metrics = $this->metrics($stock);

        if ($metrics === null) {
            return null;
        }

        [$price, $changePercent, $volume] = $metrics;
        $threshold = (float) $alert->threshold;
        $symbol = $stock->symbol;

        return match ($alert->type) {
            AlertType::PriceAbove => $price > $threshold
                ? "{$symbol} is at {$price}, above your {$threshold} alert." : null,
            AlertType::PriceBelow => $price < $threshold
                ? "{$symbol} is at {$price}, below your {$threshold} alert." : null,
            AlertType::PercentChange => $changePercent !== null && abs($changePercent) >= $threshold
                ? "{$symbol} moved {$changePercent}% today (≥ {$threshold}%)." : null,
            AlertType::PercentUp => $changePercent !== null && $changePercent >= $threshold
                ? "{$symbol} is up {$changePercent}% today (≥ +{$threshold}%)." : null,
            AlertType::PercentDown => $changePercent !== null && $changePercent <= -$threshold
                ? "{$symbol} is down {$changePercent}% today (≤ -{$threshold}%)." : null,
            AlertType::VolumeIncrease => $volume !== null && $volume >= $threshold
                ? "{$symbol} volume is {$volume} (≥ {$threshold})." : null,
            default => null,
        };
    }

    private function checkNews(StockAlert $alert, CarbonImmutable $now): ?string
    {
        $since = $alert->last_triggered_at ?? $alert->created_at ?? $now->subDay();

        $query = $alert->stock->news()
            ->where('is_matched', true)
            ->fromActiveSource()
            ->where('published_at', '>', $since);

        if ($alert->type === AlertType::ImportantNews) {
            $floor = (int) ($alert->threshold ?: self::DEFAULT_IMPORTANCE_FLOOR);
            $query->where('importance_score', '>=', $floor);
        }

        $latest = $query->orderByDesc('published_at')->first();

        if ($latest === null) {
            return null;
        }

        $prefix = $alert->type === AlertType::ImportantNews ? 'Important news' : 'News';

        return "{$prefix} for {$alert->stock->symbol}: {$latest->title}";
    }

    /**
     * Latest [price, changePercent|null, volume|null] from the cached quote,
     * falling back to the latest stored candle.
     *
     * @return array{0: float, 1: float|null, 2: float|null}|null
     */
    private function metrics(Stock $stock): ?array
    {
        $quote = MarketDataIngestor::cachedQuote($stock->id);

        if (is_array($quote) && isset($quote['price'])) {
            return [
                (float) $quote['price'],
                isset($quote['change_percent']) ? (float) $quote['change_percent'] : null,
                isset($quote['volume']) ? (float) $quote['volume'] : null,
            ];
        }

        $latest = $stock->latestPrice;

        if ($latest === null) {
            return null;
        }

        return [(float) $latest->close, null, (float) $latest->volume];
    }

    private function fire(StockAlert $alert, string $message, CarbonImmutable $now): void
    {
        if ($alert->notify_in_app || $alert->notify_telegram) {
            $this->notifications->toUser(
                $alert->user,
                NotificationCategory::Alert,
                $alert->type->value,
                "{$alert->stock->symbol} alert",
                $message,
                ['stock_id' => $alert->stock_id, 'alert_id' => $alert->id],
                "/stocks/{$alert->stock->symbol}",
                telegram: $alert->notify_telegram,
            );
        }

        $alert->forceFill([
            'last_triggered_at' => $now,
            'trigger_count' => $alert->trigger_count + 1,
        ])->save();
    }
}
