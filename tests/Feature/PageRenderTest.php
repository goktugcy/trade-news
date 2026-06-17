<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\ApiProviderSeeder;
use Database\Seeders\StockSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(StockSeeder::class);
    $this->seed(ApiProviderSeeder::class);
});

it('renders every authenticated user page', function (string $url, string $component) {
    $this->actingAs(User::factory()->create())
        ->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'dashboard' => ['/dashboard', 'Dashboard'],
    'all news' => ['/news', 'news/Index'],
    'watchlist news' => ['/news/watchlist', 'news/Index'],
    'stocks' => ['/stocks', 'stocks/Index'],
    'watchlist' => ['/watchlist', 'watchlist/Index'],
    'alerts' => ['/alerts', 'alerts/Index'],
    'telegram settings' => ['/settings/telegram', 'settings/Telegram'],
]);

it('renders every admin page', function (string $url, string $component) {
    $this->actingAs(User::factory()->admin()->create())
        ->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'admin dashboard' => ['/admin', 'admin/Dashboard'],
    'admin stocks' => ['/admin/stocks', 'admin/Stocks'],
    'admin news sources' => ['/admin/news-sources', 'admin/NewsSources'],
    'admin providers' => ['/admin/providers', 'admin/ApiProviders'],
    'admin users' => ['/admin/users', 'admin/Users'],
    'admin jobs' => ['/admin/jobs', 'admin/Jobs'],
    'admin notifications' => ['/admin/notifications', 'admin/Notifications'],
]);
