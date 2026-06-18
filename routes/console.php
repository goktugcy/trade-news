<?php

use App\Services\Telegram\TelegramBotService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Telegram webhook helpers
|--------------------------------------------------------------------------
*/

Artisan::command('tradenews:telegram-set-webhook {url?}', function (TelegramBotService $telegram, ?string $url = null) {
    $url ??= rtrim(config('app.url'), '/').'/telegram/webhook/'.config('tradenews.telegram.webhook_secret');
    $ok = $telegram->setWebhook($url, config('tradenews.telegram.webhook_secret'));
    $this->{$ok ? 'info' : 'error'}($ok ? "Webhook set to {$url}" : 'Failed to set webhook.');
})->purpose('Register the Telegram bot webhook URL');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Central data ingestion + per-user notification dispatch. Run the scheduler
| with: php artisan schedule:work  (or a system cron calling schedule:run).
*/

// Prices: checked every minute; provider refresh intervals decide when work is due.
Schedule::command('tradenews:fetch-prices --random')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// News: checked every minute; provider refresh intervals decide when work is due.
Schedule::command('tradenews:fetch-news')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Safety-net matching sweep for anything that slipped through.
Schedule::command('tradenews:match-news')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Notification dispatch runs every 5 minutes; the dispatcher itself decides
// which user rules (5m/15m/30m/1h/3h/5h/1d) are due at this minute-of-day.
Schedule::command('tradenews:dispatch-notifications')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Evaluate user stock alerts (price/volume/news) against the latest quotes.
Schedule::command('tradenews:evaluate-alerts')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Provider health probe.
Schedule::command('tradenews:check-providers')
    ->everyThirtyMinutes()
    ->onOneServer();

// Market stock universe sync: checked every minute; provider settings decide when each capability is due.
Schedule::command('tradenews:sync-market-stocks')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Nightly cleanup of stale data + duplicate news.
Schedule::command('tradenews:cleanup')
    ->dailyAt('03:30')
    ->onOneServer();
