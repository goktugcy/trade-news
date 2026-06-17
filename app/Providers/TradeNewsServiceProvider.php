<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\MarketData\FinnhubProvider;
use App\Services\MarketData\MarketDataProviderInterface;
use App\Services\MarketData\SyntheticMarketDataProvider;
use App\Services\MarketData\TwelveDataProvider;
use App\Services\News\FinnhubNewsProvider;
use App\Services\News\KapNewsProvider;
use App\Services\News\NewsProviderInterface;
use App\Services\News\SyntheticNewsProvider;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TradeNewsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MarketDataProviderInterface::class, function (): MarketDataProviderInterface {
            $driver = config('tradenews.market_data.default', 'synthetic');
            $config = config("tradenews.market_data.providers.{$driver}", []);

            return match ($driver) {
                'finnhub' => new FinnhubProvider((string) ($config['key'] ?? ''), $config['base_url']),
                'twelvedata' => new TwelveDataProvider((string) ($config['key'] ?? ''), $config['base_url']),
                default => new SyntheticMarketDataProvider,
            };
        });

        $this->app->bind(NewsProviderInterface::class, function (): NewsProviderInterface {
            $driver = config('tradenews.news.default', 'synthetic');
            $config = config("tradenews.news.providers.{$driver}", []);

            return match ($driver) {
                'finnhub' => new FinnhubNewsProvider((string) ($config['key'] ?? ''), $config['base_url']),
                'kap' => new KapNewsProvider($config['base_url'] ?? 'https://www.kap.org.tr'),
                default => new SyntheticNewsProvider,
            };
        });

        $this->app->singleton(TelegramBotService::class, function (): TelegramBotService {
            return new TelegramBotService(
                config('tradenews.telegram.token'),
                config('tradenews.telegram.api_url', 'https://api.telegram.org'),
            );
        });
    }

    public function boot(): void
    {
        // Administrators bypass every policy check.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);
    }
}
