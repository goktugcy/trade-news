<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ProviderType;
use App\Models\ApiProvider;
use App\Models\User;
use App\Services\MarketData\FallbackMarketDataProvider;
use App\Services\MarketData\FinnhubProvider;
use App\Services\MarketData\MarketDataProviderInterface;
use App\Services\MarketData\SyntheticMarketDataProvider;
use App\Services\MarketData\TwelveDataProvider;
use App\Services\News\AiSummarizerInterface;
use App\Services\News\FallbackNewsProvider;
use App\Services\News\FinnhubNewsProvider;
use App\Services\News\KapNewsProvider;
use App\Services\News\NewsProviderInterface;
use App\Services\News\NullSummarizer;
use App\Services\News\OpenAiSummarizer;
use App\Services\News\SyntheticNewsProvider;
use App\Services\Providers\ApiProviderRegistry;
use App\Services\Sync\FmpClient;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TradeNewsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MarketDataProviderInterface::class, function (): MarketDataProviderInterface {
            $registry = app(ApiProviderRegistry::class);
            $providers = $registry->marketDataProviders();

            if ($providers !== [] || $registry->hasProviderRows(ProviderType::MarketData)) {
                return new FallbackMarketDataProvider($providers);
            }

            return new FallbackMarketDataProvider([$this->configuredMarketDataProvider()]);
        });

        $this->app->bind(NewsProviderInterface::class, function (): NewsProviderInterface {
            $registry = app(ApiProviderRegistry::class);
            $providers = $registry->newsProviders();

            if ($providers !== [] || $registry->hasProviderRows(ProviderType::News)) {
                return new FallbackNewsProvider($providers);
            }

            return new FallbackNewsProvider([$this->configuredNewsProvider()]);
        });

        $this->app->singleton(TelegramBotService::class, function (): TelegramBotService {
            return new TelegramBotService(
                config('tradenews.telegram.token'),
                config('tradenews.telegram.api_url', 'https://api.telegram.org'),
            );
        });

        $this->app->singleton(FmpClient::class, function (): FmpClient {
            $provider = ApiProvider::query()
                ->where('key', 'fmp')
                ->where('type', ProviderType::MarketData->value)
                ->first();

            return new FmpClient(
                $provider?->api_key,
                $provider?->base_url ?: (string) config('tradenews.sync.fmp.base_url', 'https://financialmodelingprep.com/stable'),
                (string) config('tradenews.sync.fmp.exchange', 'NASDAQ'),
            );
        });

        // AI summaries are optional: with an OpenAI key we use it, otherwise a
        // null summarizer keeps the pipeline working with the original summary.
        $this->app->singleton(AiSummarizerInterface::class, function (): AiSummarizerInterface {
            $key = config('tradenews.ai.key');

            if (empty($key) || config('tradenews.ai.provider') !== 'openai') {
                return new NullSummarizer;
            }

            return new OpenAiSummarizer(
                (string) $key,
                (string) config('tradenews.ai.model', 'gpt-4o-mini'),
                (string) config('tradenews.ai.base_url', 'https://api.openai.com/v1'),
            );
        });
    }

    public function boot(): void
    {
        // Administrators bypass every policy check.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);
    }

    private function configuredMarketDataProvider(): MarketDataProviderInterface
    {
        $driver = config('tradenews.market_data.default', 'synthetic');
        $config = config("tradenews.market_data.providers.{$driver}", []);

        return match ($driver) {
            'finnhub' => new FinnhubProvider(
                (string) ($config['key'] ?? ''),
                (string) ($config['base_url'] ?? 'https://finnhub.io/api/v1'),
                (bool) ($config['candles_enabled'] ?? false),
            ),
            'twelvedata' => new TwelveDataProvider(
                (string) ($config['key'] ?? ''),
                (string) ($config['base_url'] ?? 'https://api.twelvedata.com'),
            ),
            default => new SyntheticMarketDataProvider,
        };
    }

    private function configuredNewsProvider(): NewsProviderInterface
    {
        $driver = config('tradenews.news.default', 'synthetic');
        $config = config("tradenews.news.providers.{$driver}", []);

        return match ($driver) {
            'finnhub' => new FinnhubNewsProvider(
                (string) ($config['key'] ?? ''),
                (string) ($config['base_url'] ?? 'https://finnhub.io/api/v1'),
            ),
            'kap' => new KapNewsProvider((string) ($config['base_url'] ?? 'https://www.kap.org.tr')),
            default => new SyntheticNewsProvider,
        };
    }
}
