<?php

declare(strict_types=1);

use App\Jobs\SendTelegramNotificationJob;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\NotificationRule;
use App\Models\Stock;
use App\Models\TelegramIntegration;
use App\Models\User;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function matchedNewsForSource(NewsSource $source, Stock $stock, string $title): NewsItem
{
    $news = NewsItem::factory()->for($source, 'source')->create([
        'title' => $title,
        'is_matched' => true,
        'published_at' => now()->subMinutes(5),
        'importance_score' => 80,
    ]);

    $news->stocks()->attach($stock->id, [
        'match_type' => 'symbol',
        'matched_term' => $stock->symbol,
        'confidence' => 1,
        'created_at' => now(),
    ]);

    return $news;
}

it('hides inactive news sources from user news feeds and stock detail pages', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);
    $activeSource = NewsSource::factory()->create(['is_active' => true]);
    $inactiveSource = NewsSource::factory()->create(['is_active' => false]);

    matchedNewsForSource($activeSource, $stock, 'Active source headline');
    matchedNewsForSource($inactiveSource, $stock, 'Inactive source headline');

    $this->actingAs($user)
        ->get('/news')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('news/Index')
            ->has('news.data', 1)
            ->where('news.data.0.title', 'Active source headline'));

    $this->actingAs($user)
        ->get('/stocks/AAPL')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('stocks/Show')
            ->has('news', 1)
            ->where('news.0.title', 'Active source headline'));
});

it('does not dispatch notifications for inactive news sources', function () {
    Queue::fake();

    $user = User::factory()->create();
    TelegramIntegration::factory()->for($user)->connected()->create();
    $stock = Stock::factory()->create();
    $user->watchlist()->create(['stock_id' => $stock->id, 'alerts_enabled' => true]);

    $inactiveSource = NewsSource::factory()->create(['is_active' => false]);
    matchedNewsForSource($inactiveSource, $stock, 'Inactive notification headline');

    $rule = NotificationRule::factory()->for($user)->create([
        'only_watchlist' => true,
        'min_importance' => 0,
        'last_dispatched_at' => now()->subDay(),
        'is_active' => true,
    ]);

    expect(app(NotificationDispatcher::class)->processRule($rule))->toBe(0);
    Queue::assertNotPushed(SendTelegramNotificationJob::class);
});
