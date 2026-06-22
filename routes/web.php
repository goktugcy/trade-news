<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsInteractionController;
use App\Http\Controllers\NewsSourcePreferenceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationRuleController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\StockAlertController;
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
    Route::put('onboarding/preferences', [OnboardingController::class, 'update'])->name('onboarding.preferences.update');

    // News feeds (static routes before the {newsItem} wildcard)
    Route::get('news', [NewsController::class, 'index'])->name('news.index');
    Route::get('news/live', [NewsController::class, 'live'])->name('news.live');
    Route::get('news/watchlist', [NewsController::class, 'watchlist'])->name('news.watchlist');
    Route::get('news/saved', [NewsController::class, 'saved'])->name('news.saved');
    Route::patch('news/sources/{newsSource}', [NewsSourcePreferenceController::class, 'update'])->name('news.sources.update');
    Route::post('news/{newsItem}/react', [NewsInteractionController::class, 'react'])->name('news.react');
    Route::post('news/{newsItem}/save', [NewsInteractionController::class, 'save'])->name('news.save');
    Route::delete('news/{newsItem}/save', [NewsInteractionController::class, 'unsave'])->name('news.unsave');
    Route::post('news/{newsItem}/translate', [NewsInteractionController::class, 'translate'])->name('news.translate');

    // Stocks (specific routes before the {stock} wildcard)
    Route::get('stocks', [StockController::class, 'index'])->name('stocks.index');
    Route::get('stocks/search', [StockController::class, 'search'])->name('stocks.search');
    Route::get('stocks/live', [StockController::class, 'liveQuotes'])->name('stocks.live');
    Route::get('stocks/{stock}', [StockController::class, 'show'])->name('stocks.show');
    Route::post('stocks/{stock}/analysis/translate', [StockController::class, 'translateAnalysis'])->name('stocks.analysis.translate');

    // Watchlist management
    Route::get('watchlist', [WatchlistController::class, 'index'])->name('watchlist.index');
    Route::post('watchlist', [WatchlistController::class, 'store'])->name('watchlist.store');
    Route::patch('watchlist/{watchlist}/alert', [WatchlistController::class, 'toggleAlert'])->name('watchlist.alert');
    Route::delete('watchlist/{watchlist}', [WatchlistController::class, 'destroy'])->name('watchlist.destroy');

    // In-app notification inbox
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    // Custom stock alerts (price/volume/news conditions)
    Route::post('alerts/stock', [StockAlertController::class, 'store'])->name('stock-alerts.store');
    Route::put('alerts/stock/{stockAlert}', [StockAlertController::class, 'update'])->name('stock-alerts.update');
    Route::delete('alerts/stock/{stockAlert}', [StockAlertController::class, 'destroy'])->name('stock-alerts.destroy');

    // Notification rules (alerts)
    Route::get('alerts', [NotificationRuleController::class, 'index'])->name('alerts.index');
    Route::post('alerts', [NotificationRuleController::class, 'store'])->name('alerts.store');
    Route::put('alerts/{notificationRule}', [NotificationRuleController::class, 'update'])->name('alerts.update');
    Route::delete('alerts/{notificationRule}', [NotificationRuleController::class, 'destroy'])->name('alerts.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
