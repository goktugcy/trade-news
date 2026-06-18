<?php

declare(strict_types=1);

use App\Jobs\SendTelegramNotificationJob;
use App\Models\NewsItem;
use App\Models\NotificationRule;
use App\Models\Stock;
use App\Models\TelegramIntegration;
use App\Models\User;
use App\Services\Notification\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function dailyAlertUser(string $timezone): NotificationRule
{
    $user = User::factory()->create(['timezone' => $timezone]);
    TelegramIntegration::factory()->for($user)->connected()->create();

    $stock = Stock::factory()->create();
    $user->watchlist()->create(['stock_id' => $stock->id, 'alerts_enabled' => true]);

    $news = NewsItem::factory()->create([
        'is_matched' => true,
        'published_at' => now()->subMinutes(30),
        'importance_score' => 80,
    ]);
    $news->stocks()->attach($stock->id, [
        'match_type' => 'symbol', 'matched_term' => $stock->symbol, 'confidence' => 1, 'created_at' => now(),
    ]);

    return NotificationRule::factory()->for($user)->create([
        'interval_minutes' => 1440, // once a day → only at local midnight
        'only_watchlist' => true,
        'min_importance' => 0,
        'last_dispatched_at' => now()->subDays(2),
        'is_active' => true,
    ]);
}

it('fires a daily rule at the user local midnight, not server midnight', function () {
    Queue::fake();
    dailyAlertUser('Europe/Istanbul'); // UTC+3

    // 21:00 UTC == 00:00 in Europe/Istanbul → due for that user.
    $queued = app(NotificationDispatcher::class)->dispatchDue(CarbonImmutable::parse('2026-06-17 21:00:00', 'UTC'));

    expect($queued)->toBe(1);
    Queue::assertPushed(SendTelegramNotificationJob::class);
});

it('does not fire a daily rule at the wrong local time', function () {
    Queue::fake();
    dailyAlertUser('Europe/Istanbul');

    // 00:00 UTC == 03:00 in Europe/Istanbul → not midnight there, not due.
    $queued = app(NotificationDispatcher::class)->dispatchDue(CarbonImmutable::parse('2026-06-17 00:00:00', 'UTC'));

    expect($queued)->toBe(0);
    Queue::assertNothingPushed();
});
