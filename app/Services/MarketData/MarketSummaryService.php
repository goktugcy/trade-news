<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\Enums\ProviderType;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\Providers\ApiProviderRegistry;
use App\Support\Presenters\StockPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds the cached market-summary payloads (top movers + the scrolling
 * top-bar ticker) shared across the app. The heavy ranking scan runs only in
 * the scheduled warm() pass; web requests read the precomputed cache (O(1)).
 */
class MarketSummaryService
{
    /** Read TTL must outlive the warm cadence so the value never expires mid-cycle. */
    private function ttl(): int
    {
        return (int) config('tradenews.cache.market_summary_ttl', 60) * 3;
    }

    /**
     * Hot path: read the precomputed top movers (never computes).
     *
     * @return array{gainers: array<int, mixed>, losers: array<int, mixed>}
     */
    public function cachedTopMovers(): array
    {
        return Cache::get($this->cacheKey('top-movers'), ['gainers' => [], 'losers' => []]);
    }

    /**
     * Hot path: read the precomputed ticker (never computes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function cachedTicker(): array
    {
        return Cache::get($this->cacheKey('ticker'), []);
    }

    /**
     * Scheduled warm pass: scan once and cache both payloads. Runs off the
     * web-request path (tradenews:warm-market-summary, every minute).
     */
    public function warm(int $perSide = 8, int $moversLimit = 5): void
    {
        $ranked = collect($this->rankedRows());

        $ticker = $ranked->take($perSide)
            ->concat($ranked->reverse()->take($perSide))
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

        $topMovers = [
            'gainers' => $ranked->take($moversLimit)->all(),
            'losers' => $ranked->reverse()->take($moversLimit)->values()->all(),
        ];

        Cache::put($this->cacheKey('ticker'), $ticker, $this->ttl());
        Cache::put($this->cacheKey('top-movers'), $topMovers, $this->ttl());
    }

    /**
     * Active stocks with a known quote, ranked by % change (gainers first).
     * Batches the quote-cache reads and the previous-close fallback into a
     * handful of queries instead of one-per-stock.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rankedRows(): array
    {
        /** @var Collection<int, Stock> $stocks */
        $stocks = Stock::query()->active()->with('latestPrice')->get();

        $quotes = $this->resolveQuotes($stocks);

        return $stocks
            ->map(fn (Stock $stock) => StockPresenter::rowWithQuote($stock, $quotes[$stock->id] ?? []))
            ->filter(fn ($row) => $row['change_percent'] !== null)
            ->sortByDesc('change_percent')
            ->values()
            ->all();
    }

    /**
     * Resolve a quote per stock, mirroring StockPresenter::quote() semantics but
     * batched: one Cache::many() for cached quotes + one windowed query for the
     * previous-close fallback of stocks without a usable cached quote.
     *
     * @param  Collection<int, Stock>  $stocks
     * @return array<int, array<string, mixed>>
     */
    private function resolveQuotes(Collection $stocks): array
    {
        $hideSynthetic = StockPresenter::hideSyntheticMarketData();

        $keyForId = [];
        foreach ($stocks as $stock) {
            $keyForId[$stock->id] = MarketDataIngestor::quoteCacheKey($stock->id);
        }

        /** @var array<string, mixed> $cached */
        $cached = Cache::many(array_values($keyForId));

        $quotes = [];
        /** @var array<int, Stock> $needFallback */
        $needFallback = [];

        foreach ($stocks as $stock) {
            $hit = $cached[$keyForId[$stock->id]] ?? null;

            if (is_array($hit) && ! StockPresenter::shouldHideCachedQuote($hit, $hideSynthetic)) {
                $quotes[$stock->id] = $hit;

                continue;
            }

            if ($stock->latestPrice !== null) {
                $needFallback[$stock->id] = $stock;
            }
        }

        $previousClose = $this->previousCloseMap($needFallback, $hideSynthetic);

        foreach ($needFallback as $id => $stock) {
            $latest = $stock->latestPrice;
            $prev = $previousClose[$id] ?? $latest->open;
            $change = round($latest->close - $prev, 4);

            $quotes[$id] = [
                'price' => $latest->close,
                'change' => $change,
                'change_percent' => $prev != 0.0 ? round(($change / $prev) * 100, 2) : 0.0,
                'at' => $latest->price_at->toIso8601String(),
            ];
        }

        return $quotes;
    }

    /**
     * Batched previous-close lookup: for each fallback stock, the close of the
     * most recent candle before its latest, in the same timeframe.
     *
     * @param  array<int, Stock>  $fallback  keyed by stock id (each has latestPrice loaded)
     * @return array<int, float>
     */
    private function previousCloseMap(array $fallback, bool $hideSynthetic): array
    {
        if ($fallback === []) {
            return [];
        }

        $ids = array_keys($fallback);

        $sub = DB::table('stock_prices')
            ->select('stock_id', 'timeframe', 'close', 'price_at')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY stock_id, timeframe ORDER BY price_at DESC) AS rn')
            ->whereIn('stock_id', $ids)
            ->when($hideSynthetic, fn ($q) => $q->where('source_kind', '!=', StockPrice::SOURCE_SYNTHETIC));

        $rows = DB::query()->fromSub($sub, 'r')->where('rn', '<=', 2)->get()->groupBy('stock_id');

        $map = [];
        foreach ($fallback as $id => $stock) {
            $latest = $stock->latestPrice;
            $timeframe = $latest->timeframe->value;
            $latestAt = $latest->price_at;

            $prev = ($rows[$id] ?? collect())
                ->first(fn ($row) => $row->timeframe === $timeframe
                    && Carbon::parse($row->price_at)->lt($latestAt));

            if ($prev !== null) {
                $map[$id] = (float) $prev->close;
            }
        }

        return $map;
    }

    private function cacheKey(string $name): string
    {
        $mode = app(ApiProviderRegistry::class)->hasActiveRealProvider(ProviderType::MarketData)
            ? 'api'
            : 'development';

        return "tn:{$name}:{$mode}";
    }
}
