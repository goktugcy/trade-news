<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NotificationRuleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\Webhooks\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

/*
|--------------------------------------------------------------------------
| Telegram webhook (public, CSRF-exempt — see bootstrap/app.php)
|--------------------------------------------------------------------------
*/
Route::post('telegram/webhook/{secret}', TelegramWebhookController::class)
    ->name('telegram.webhook');

/*
|--------------------------------------------------------------------------
| Authenticated application
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // News feeds
    Route::get('news', [NewsController::class, 'index'])->name('news.index');
    Route::get('news/watchlist', [NewsController::class, 'watchlist'])->name('news.watchlist');

    // Stocks (specific routes before the {stock} wildcard)
    Route::get('stocks', [StockController::class, 'index'])->name('stocks.index');
    Route::get('stocks/search', [StockController::class, 'search'])->name('stocks.search');
    Route::get('stocks/{stock}', [StockController::class, 'show'])->name('stocks.show');
    Route::get('stocks/{stock}/candles', [StockController::class, 'candles'])->name('stocks.candles');

    // Watchlist management
    Route::get('watchlist', [WatchlistController::class, 'index'])->name('watchlist.index');
    Route::post('watchlist', [WatchlistController::class, 'store'])->name('watchlist.store');
    Route::patch('watchlist/{watchlist}/alert', [WatchlistController::class, 'toggleAlert'])->name('watchlist.alert');
    Route::delete('watchlist/{watchlist}', [WatchlistController::class, 'destroy'])->name('watchlist.destroy');

    // Notification rules (alerts)
    Route::get('alerts', [NotificationRuleController::class, 'index'])->name('alerts.index');
    Route::post('alerts', [NotificationRuleController::class, 'store'])->name('alerts.store');
    Route::put('alerts/{notificationRule}', [NotificationRuleController::class, 'update'])->name('alerts.update');
    Route::delete('alerts/{notificationRule}', [NotificationRuleController::class, 'destroy'])->name('alerts.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
