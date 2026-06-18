<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Enums\ProviderType;
use App\Models\ApiProvider;
use App\Services\MarketData\FinnhubProvider;
use App\Services\MarketData\MarketDataProviderInterface;
use App\Services\MarketData\SyntheticMarketDataProvider;
use App\Services\MarketData\TwelveDataProvider;
use App\Services\News\FinnhubNewsProvider;
use App\Services\News\KapNewsProvider;
use App\Services\News\NewsProviderInterface;
use App\Services\News\RssNewsProvider;
use App\Services\News\SyntheticNewsProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ApiProviderRegistry
{
    public const SYNTHETIC_MARKET_KEY = 'synthetic';

    public const SYNTHETIC_NEWS_KEY = 'synthetic-news';

    /** @var array<int, string> */
    public const MARKET_DATA_FETCH_CAPABILITIES = ['quotes', 'candles'];

    /**
     * @return array<int, string>
     */
    public static function syntheticKeys(): array
    {
        return array_values(array_unique([self::SYNTHETIC_MARKET_KEY, self::SYNTHETIC_NEWS_KEY, 'synthetic']));
    }

    public static function isSyntheticKey(?string $key): bool
    {
        return $key !== null && in_array($key, self::syntheticKeys(), true);
    }

    public function hasActiveRealProvider(ProviderType $type): bool
    {
        return ApiProvider::query()
            ->where('type', $type->value)
            ->where('is_active', true)
            ->where('key', '!=', $this->syntheticKeyFor($type))
            ->get()
            ->contains(fn (ApiProvider $provider): bool => $this->isConfiguredRealProvider($type, $provider));
    }

    public function hasProviderRows(ProviderType $type): bool
    {
        return ApiProvider::query()
            ->where('type', $type->value)
            ->exists();
    }

    public function shouldHideSyntheticMarketData(): bool
    {
        return $this->hasActiveRealProvider(ProviderType::MarketData)
            || ApiProvider::query()
                ->where('type', ProviderType::MarketData->value)
                ->where('key', self::SYNTHETIC_MARKET_KEY)
                ->where('is_active', false)
                ->exists();
    }

    /**
     * @return Collection<int, ApiProvider>
     */
    public function activeProviderRows(ProviderType $type, bool $dueOnly = false): Collection
    {
        $query = ApiProvider::query()
            ->where('type', $type->value)
            ->where('is_active', true);

        if ($this->hasActiveRealProvider($type)) {
            $query->where('key', '!=', $this->syntheticKeyFor($type));
        }

        $providers = $query
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if (! $dueOnly) {
            return $providers;
        }

        return $providers
            ->filter(fn (ApiProvider $provider): bool => $provider->isDueForFetch())
            ->values();
    }

    /**
     * @return Collection<int, ApiProvider>
     */
    public function dueProviderRows(ProviderType $type): Collection
    {
        return $this->activeProviderRows($type, dueOnly: true);
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return Collection<int, ApiProvider>
     */
    public function activeProviderRowsForCapabilities(
        ProviderType $type,
        array $capabilities,
        bool $dueOnly = false,
        bool $concreteOnly = false,
    ): Collection {
        return $this->activeProviderRows($type, $dueOnly)
            ->filter(fn (ApiProvider $provider): bool => $this->providerHasAnyCapability($provider, $capabilities))
            ->filter(fn (ApiProvider $provider): bool => ! $concreteOnly || $this->hasConcreteProvider($type, $provider))
            ->values();
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return Collection<int, ApiProvider>
     */
    public function dueProviderRowsForCapabilities(
        ProviderType $type,
        array $capabilities,
        bool $concreteOnly = false,
    ): Collection {
        return $this->activeProviderRowsForCapabilities($type, $capabilities, dueOnly: true, concreteOnly: $concreteOnly);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    public function hasActiveProviderRowsForCapabilities(
        ProviderType $type,
        array $capabilities,
        bool $concreteOnly = false,
    ): bool {
        return $this->activeProviderRowsForCapabilities($type, $capabilities, concreteOnly: $concreteOnly)->isNotEmpty();
    }

    /**
     * @param  Collection<int, ApiProvider>  $providers
     */
    public function markFetched(Collection $providers, ?Carbon $at = null): void
    {
        $at ??= now();

        $providers->each(fn (ApiProvider $provider): mixed => $provider->forceFill([
            'last_fetched_at' => $at,
        ])->save());
    }

    public function fetchLimitFor(ProviderType $type, int $default = 50): int
    {
        $provider = $this->dueProviderRows($type)->first()
            ?? $this->activeProviderRows($type)->first();

        if (! $provider instanceof ApiProvider) {
            return $default;
        }

        return max(1, $provider->fetch_limit ?: $default);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    public function fetchLimitForCapabilities(ProviderType $type, array $capabilities, int $default = 50): int
    {
        $provider = $this->dueProviderRowsForCapabilities($type, $capabilities, concreteOnly: true)->first()
            ?? $this->activeProviderRowsForCapabilities($type, $capabilities, concreteOnly: true)->first();

        if (! $provider instanceof ApiProvider) {
            return $default;
        }

        return max(1, $provider->fetch_limit ?: $default);
    }

    /**
     * @return array<int, string>
     */
    public function activeProviderKeys(ProviderType $type): array
    {
        return $this->activeProviderRows($type)
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @return array<int, MarketDataProviderInterface>
     */
    public function marketDataProviders(): array
    {
        return $this->activeProviderRows(ProviderType::MarketData)
            ->map(fn (ApiProvider $provider): ?MarketDataProviderInterface => $this->makeMarketDataProvider($provider))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, NewsProviderInterface>
     */
    public function newsProviders(): array
    {
        return $this->activeProviderRows(ProviderType::News)
            ->map(fn (ApiProvider $provider): ?NewsProviderInterface => $this->makeNewsProvider($provider))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Resolve a single concrete news provider by its api_providers.key.
     */
    public function makeNewsProviderByKey(string $key): ?NewsProviderInterface
    {
        $provider = ApiProvider::query()
            ->where('type', ProviderType::News->value)
            ->where('key', $key)
            ->first();

        return $provider instanceof ApiProvider ? $this->makeNewsProvider($provider) : null;
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    public function providerHasAnyCapability(ApiProvider $provider, array $capabilities): bool
    {
        return array_intersect($this->capabilitiesFor($provider), $capabilities) !== [];
    }

    private function syntheticKeyFor(ProviderType $type): string
    {
        return $type === ProviderType::MarketData
            ? self::SYNTHETIC_MARKET_KEY
            : self::SYNTHETIC_NEWS_KEY;
    }

    private function hasConcreteProvider(ProviderType $type, ApiProvider $provider): bool
    {
        return match ($type) {
            ProviderType::MarketData => $this->makeMarketDataProvider($provider) !== null,
            ProviderType::News => $this->makeNewsProvider($provider) !== null,
        };
    }

    private function isConfiguredRealProvider(ProviderType $type, ApiProvider $provider): bool
    {
        if (self::isSyntheticKey($provider->key)) {
            return false;
        }

        return match ($type) {
            ProviderType::MarketData => match ($provider->key) {
                'rapidapi-bist100' => $this->providerApiKey($provider) !== null
                    && $this->providerHasAnyCapability($provider, ['quotes', 'list']),
                default => $this->makeMarketDataProvider($provider) !== null,
            },
            ProviderType::News => $this->makeNewsProvider($provider) !== null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function capabilitiesFor(ApiProvider $provider): array
    {
        if (is_array($provider->capabilities) && $provider->capabilities !== []) {
            return array_values(array_map(
                fn (mixed $capability): string => (string) $capability,
                $provider->capabilities,
            ));
        }

        return match ($provider->key) {
            'finnhub', 'twelvedata', self::SYNTHETIC_MARKET_KEY => self::MARKET_DATA_FETCH_CAPABILITIES,
            'fmp' => ['list', 'profiles'],
            'rapidapi-bist100' => ['list', 'quotes'],
            'finnhub-news', 'kap', 'rss', self::SYNTHETIC_NEWS_KEY => ['news'],
            default => [],
        };
    }

    private function makeMarketDataProvider(ApiProvider $provider): ?MarketDataProviderInterface
    {
        return match ($provider->key) {
            'finnhub' => $this->providerApiKey($provider) === null ? null : new FinnhubProvider(
                $this->providerApiKey($provider),
                $provider->base_url ?: (string) config('tradenews.market_data.providers.finnhub.base_url'),
                (bool) config('tradenews.market_data.providers.finnhub.candles_enabled', false),
            ),
            'twelvedata' => $this->providerApiKey($provider) === null ? null : new TwelveDataProvider(
                $this->providerApiKey($provider),
                $provider->base_url ?: (string) config('tradenews.market_data.providers.twelvedata.base_url'),
            ),
            self::SYNTHETIC_MARKET_KEY => new SyntheticMarketDataProvider,
            default => null,
        };
    }

    private function makeNewsProvider(ApiProvider $provider): ?NewsProviderInterface
    {
        return match ($provider->key) {
            'finnhub-news' => $this->providerApiKey($provider) === null ? null : new FinnhubNewsProvider(
                $this->providerApiKey($provider),
                $provider->base_url ?: (string) config('tradenews.news.providers.finnhub.base_url'),
            ),
            'kap' => new KapNewsProvider(
                $provider->base_url ?: (string) config('tradenews.news.providers.kap.base_url', 'https://www.kap.org.tr'),
            ),
            'rss' => new RssNewsProvider(
                timeout: (int) config('tradenews.news.providers.rss.timeout', 12),
                perFeedLimit: (int) config('tradenews.news.providers.rss.per_feed_limit', 40),
            ),
            self::SYNTHETIC_NEWS_KEY => new SyntheticNewsProvider,
            default => null,
        };
    }

    private function providerApiKey(ApiProvider $provider): ?string
    {
        $apiKey = trim((string) $provider->api_key);

        return $apiKey === '' ? null : $apiKey;
    }
}
