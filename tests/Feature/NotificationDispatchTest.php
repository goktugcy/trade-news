<?php

declare(strict_types=1);

use App\Jobs\SendTelegramNotificationJob;
use App\Models\NewsItem;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\Stock;
use App\Models\TelegramIntegration;
use App\Models\User;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function watchlistUserWithNews(): array
{
    $user = User::factory()->create();
    TelegramIntegration::factory()->for($user)->connected()->create();

    $stock = Stock::factory()->create();
    $user->watchlist()->create(['stock_id' => $stock->id, 'alerts_enabled' => true]);

    $news = NewsItem::factory()->create([
        'is_matched' => true,
        'published_at' => now()->subMinutes(5),
        'importance_score' => 60,
    ]);
    $news->stocks()->attach($stock->id, [
        'match_type' => 'symbol', 'matched_term' => $stock->symbol, 'confidence' => 1, 'created_at' => now(),
    ]);

    $rule = NotificationRule::factory()->for($user)->create([
        'only_watchlist' => true,
        'min_importance' => 0,
        'last_dispatched_at' => now()->subDay(),
        'is_active' => true,
    ]);

    return [$user, $rule, $news];
}

it('queues a telegram alert for matching watchlist news', function () {
    Queue::fake();
    [$user, $rule, $news] = watchlistUserWithNews();

    $queued = app(NotificationDispatcher::class)->processRule($rule);

    expect($queued)->toBe(1);
    Queue::assertPushed(SendTelegramNotificationJob::class);

    $log = Notification::where('user_id', $user->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->news_item_id)->toBe($news->id)
        ->and($log->status)->toBe(Notification::STATUS_QUEUED);
});

it('does not re-notify about the same news item', function () {
    Queue::fake();
    [, $rule] = watchlistUserWithNews();

    app(NotificationDispatcher::class)->processRule($rule);
    $rule->update(['last_dispatched_at' => now()->subDay()]);
    $second = app(NotificationDispatcher::class)->processRule($rule);

    expect($second)->toBe(0);
});

it('skips users without an active telegram integration', function () {
    Queue::fake();
    $user = User::factory()->create(); // no telegram integration
    $stock = Stock::factory()->create();
    $user->watchlist()->create(['stock_id' => $stock->id, 'alerts_enabled' => true]);
    $rule = NotificationRule::factory()->for($user)->create(['only_watchlist' => true]);

    expect(app(NotificationDispatcher::class)->processRule($rule))->toBe(0);
    Queue::assertNothingPushed();
});

it('delivers a queued notification over telegram', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    config(['tradenews.telegram.token' => 'test-token']);

    $user = User::factory()->create();
    TelegramIntegration::factory()->for($user)->connected()->create();
    $news = NewsItem::factory()->create(['is_matched' => true]);
    $stock = Stock::factory()->create();
    $news->stocks()->attach($stock->id, ['match_type' => 'symbol', 'matched_term' => $stock->symbol, 'confidence' => 1, 'created_at' => now()]);

    $notification = Notification::factory()->for($user)->create([
        'news_item_id' => $news->id,
        'status' => Notification::STATUS_QUEUED,
        'sent_at' => null,
    ]);

    app(SendTelegramNotificationJob::class, ['notificationId' => $notification->id])
        ->handle(app(TelegramBotService::class));

    expect($notification->fresh()->status)->toBe(Notification::STATUS_SENT);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage'));
});
