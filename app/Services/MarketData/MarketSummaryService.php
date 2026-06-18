<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\Enums\ProviderType;
use App\Models\Stock;
use App\Services\Providers\ApiProviderRegistry;
use App\Support\Presenters\StockPresenter;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the cached market-summary payloads (top movers + the scrolling
 * top-bar ticker) used across the app. Computed once and shared by all users.
 */
class MarketSummaryService
{
    /**
     * @return array{gainers: array<int, mixed>, losers: array<int, mixed>}
     */
    public function topMovers(int $limit = 5): array
    {
        return Cache::remember($this->cacheKey('top-movers'), config('tradenews.cache.market_summary_ttl', 60), function () use ($limit): array {
            $sorted = collect($this->rankedRows());

            return [
                'gainers' => $sorted->take($limit)->all(),
                'losers' => $sorted->reverse()->take($limit)->values()->all(),
            ];
        });
    }

    /**
     * Flat list of the day's biggest gainers + losers for the scrolling ticker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ticker(int $perSide = 8): array
    {
        return Cache::remember($this->cacheKey('ticker'), config('tradenews.cache.market_summary_ttl', 60), function () use ($perSide): array {
            $sorted = collect($this->rankedRows());

            $gainers = $sorted->take($perSide);
            $losers = $sorted->reverse()->take($perSide);

            return $gainers->concat($losers)
                ->unique('id')
                ->map(fn ($row) => [
                    'symbol' => $row['symbol'],
                    'market' => $row['market'],
                    'price' => $row['price'],
                    'currency' => $row['currency'],
                    'change_percent' => $row['change_percent'],
                ])
                ->values()
                ->all();
        });
    }

    /**
     * Active stocks with a known quote, ranked by % change (gainers first).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rankedRows(): array
    {
        return Stock::query()
            ->active()
            ->with('latestPrice')
            ->get()
            ->map(fn (Stock $stock) => StockPresenter::row($stock))
            ->filter(fn ($row) => $row['change_percent'] !== null)
            ->sortByDesc('change_percent')
            ->values()
            ->all();
    }

    private function cacheKey(string $name): string
    {
        $mode = app(ApiProviderRegistry::class)->hasActiveRealProvider(ProviderType::MarketData)
            ? 'api'
            : 'development';

        return "tn:{$name}:{$mode}";
    }
}
