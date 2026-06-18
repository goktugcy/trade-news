<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Enums\ProviderType;
use App\Enums\Timeframe;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\MarketData\MarketDataIngestor;
use App\Services\Providers\ApiProviderRegistry;
use App\Support\Presenters\NewsPresenter;
use App\Support\Presenters\StockPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    /**
     * Searchable stock list grouped by market.
     */
    public function index(Request $request): Response
    {
        $market = $request->string('market')->upper()->toString();
        $search = trim($request->string('q')->toString());
        $watchlistIds = $request->user()->watchlist()->pluck('stock_id')->all();

        $stocks = Stock::query()
            ->active()
            ->with('latestPrice')
            ->when(
                in_array($market, [Market::BIST->value, Market::NASDAQ->value], true),
                fn (Builder $q) => $q->where('market', $market),
            )
            ->when($search !== '', fn (Builder $q) => $q->search($search))
            ->orderBy('symbol')
            ->get()
            ->map(fn (Stock $stock) => StockPresenter::row($stock, [
                'in_watchlist' => in_array($stock->id, $watchlistIds, true),
            ]));

        return Inertia::render('stocks/Index', [
            'stocks' => $stocks,
            'filters' => [
                'market' => $market ?: 'ALL',
                'q' => $search ?: null,
            ],
            'options' => ['markets' => Market::options()],
        ]);
    }

    /**
     * Stock detail page (route-model-bound by symbol).
     */
    public function show(Request $request, Stock $stock): Response
    {
        $watchlistEntry = $request->user()->watchlist()
            ->where('stock_id', $stock->id)
            ->first();

        $relatedNews = $stock->news()
            ->where('is_matched', true)
            ->whereHas('source', fn (Builder $q) => $q->where('is_active', true))
            ->with(['stocks:id,symbol,market', 'source:id,name', 'sources.source:id,name'])
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();

        return Inertia::render('stocks/Show', [
            'stock' => StockPresenter::row($stock->load('latestPrice'), [
                'in_watchlist' => $watchlistEntry !== null,
                'alerts_enabled' => $watchlistEntry !== null && $watchlistEntry->alerts_enabled,
                'watchlist_id' => $watchlistEntry?->id,
            ]),
            'news' => NewsPresenter::collection($relatedNews),
            'timeframes' => array_map(fn (Timeframe $t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ], Timeframe::cases()),
        ]);
    }

    /**
     * OHLC candle data for the chart (read from stored prices, JSON).
     */
    public function candles(Request $request, Stock $stock, ApiProviderRegistry $providers): JsonResponse
    {
        $timeframe = Timeframe::tryFrom($request->string('timeframe')->toString()) ?? Timeframe::FiveMinutes;
        $apiActive = $providers->hasActiveRealProvider(ProviderType::MarketData);
        $hideSynthetic = $providers->shouldHideSyntheticMarketData();

        $candles = $stock->prices()
            ->timeframe($timeframe)
            ->withoutSyntheticWhenApiActive($hideSynthetic)
            ->latest('price_at')
            ->limit(300)
            ->get(['open', 'high', 'low', 'close', 'volume', 'price_at', 'provider_key', 'source_kind'])
            ->sortBy('price_at')
            ->values()
            ->map(fn (StockPrice $price): array => $this->candlePayload($price))
            ->all();

        return response()->json([
            'symbol' => $stock->symbol,
            'timeframe' => $timeframe->value,
            'candles' => $this->withCachedQuoteCandle($candles, $stock, $timeframe, $hideSynthetic),
            'source' => [
                'mode' => $apiActive ? 'api' : 'development',
                'api_active' => $apiActive,
                'synthetic_hidden' => $hideSynthetic,
                'provider_keys' => $providers->activeProviderKeys(ProviderType::MarketData),
            ],
        ]);
    }

    /**
     * Typeahead search used by the watchlist add box (JSON).
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        if (mb_strlen($term) < 1) {
            return response()->json(['results' => []]);
        }

        $results = Stock::query()
            ->active()
            ->search($term)
            ->orderBy('symbol')
            ->limit(10)
            ->get(['id', 'symbol', 'name', 'market'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'symbol' => $s->symbol,
                'name' => $s->name,
                'market' => $s->market->value,
            ]);

        return response()->json(['results' => $results]);
    }

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float, provider_key: string|null, source_kind: string}>  $candles
     * @return array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float, provider_key: string|null, source_kind: string}>
     */
    private function withCachedQuoteCandle(array $candles, Stock $stock, Timeframe $timeframe, bool $hideSynthetic): array
    {
        $collection = collect($candles);
        $quote = MarketDataIngestor::cachedQuote($stock->id);

        if (! is_array($quote) || ! isset($quote['price'], $quote['at'])) {
            return $collection->values()->all();
        }

        $providerKey = is_string($quote['provider_key'] ?? null) ? $quote['provider_key'] : null;
        $sourceKind = is_string($quote['source_kind'] ?? null) ? $quote['source_kind'] : StockPrice::SOURCE_QUOTE;

        if ($this->shouldHideQuote($hideSynthetic, $providerKey, $sourceKind)) {
            return $collection->values()->all();
        }

        $time = $this->bucketTime(CarbonImmutable::parse((string) $quote['at']), $timeframe);
        $price = (float) $quote['price'];

        return $collection
            ->reject(fn (array $candle): bool => (int) $candle['time'] === $time)
            ->push([
                'time' => $time,
                'open' => $timeframe->isIntraday() ? $price : (float) ($quote['open'] ?? $price),
                'high' => $timeframe->isIntraday() ? $price : (float) ($quote['high'] ?? $price),
                'low' => $timeframe->isIntraday() ? $price : (float) ($quote['low'] ?? $price),
                'close' => $price,
                'volume' => (float) ($quote['volume'] ?? 0),
                'provider_key' => $providerKey,
                'source_kind' => $sourceKind,
            ])
            ->sortBy('time')
            ->values()
            ->all();
    }

    /**
     * @return array{time: int, open: float, high: float, low: float, close: float, volume: float, provider_key: string|null, source_kind: string}
     */
    private function candlePayload(StockPrice $price): array
    {
        return [
            'time' => (int) $price->price_at->getTimestamp(),
            'open' => (float) $price->open,
            'high' => (float) $price->high,
            'low' => (float) $price->low,
            'close' => (float) $price->close,
            'volume' => (float) $price->volume,
            'provider_key' => $price->provider_key,
            'source_kind' => $price->source_kind,
        ];
    }

    private function shouldHideQuote(bool $hideSynthetic, ?string $providerKey, string $sourceKind): bool
    {
        return $hideSynthetic && (
            $providerKey === null
            || ApiProviderRegistry::isSyntheticKey($providerKey)
            || $sourceKind === StockPrice::SOURCE_SYNTHETIC
        );
    }

    private function bucketTime(CarbonImmutable $at, Timeframe $timeframe): int
    {
        return intdiv($at->getTimestamp(), $timeframe->seconds()) * $timeframe->seconds();
    }
}
