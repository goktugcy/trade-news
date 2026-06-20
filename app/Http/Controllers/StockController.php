<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiTask;
use App\Enums\Market;
use App\Enums\ProviderType;
use App\Enums\Timeframe;
use App\Models\Stock;
use App\Models\StockAiAnalysis;
use App\Models\StockPrice;
use App\Services\Ai\AiTaskService;
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
    private const string DEFAULT_CHART_RANGE = 'latest';

    /**
     * @var array<string, array{label: string, limit: int}>
     */
    private const array CHART_RANGES = [
        'latest' => ['label' => 'Latest', 'limit' => 300],
        '1h' => ['label' => '1H', 'limit' => 300],
        '3h' => ['label' => '3H', 'limit' => 300],
        '24h' => ['label' => '24H', 'limit' => 500],
        '1mo' => ['label' => '1M', 'limit' => 900],
        '3mo' => ['label' => '3M', 'limit' => 1_200],
        '5mo' => ['label' => '5M', 'limit' => 1_500],
        '1y' => ['label' => '1Y', 'limit' => 800],
        '5y' => ['label' => '5Y', 'limit' => 2_000],
    ];

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

        $uid = $request->user()->id;
        $disabledSourceIds = $request->user()->disabledNewsSources()->pluck('news_source_id');

        $relatedNews = $stock->news()
            ->where('is_matched', true)
            ->whereHas('source', fn (Builder $q) => $q->where('is_active', true))
            ->with([
                'stocks:id,symbol,market', 'source:id,name', 'sources.source:id,name',
                'reactionForUser' => fn ($q) => $q->where('user_id', $uid),
                'savedForUser' => fn ($q) => $q->where('user_id', $uid),
            ])
            ->withCount(['likes', 'dislikes'])
            ->when(
                $disabledSourceIds->isNotEmpty(),
                fn (Builder $q) => $q->whereNotIn('source_id', $disabledSourceIds),
            )
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
            'analysis' => $this->analysisPayload($stock),
            'timeframes' => array_map(fn (Timeframe $t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ], Timeframe::cases()),
            'chartRanges' => $this->chartRangeOptions(),
        ]);
    }

    /**
     * The latest cached AI analysis for the stock detail page. Read-only — never
     * triggers AI generation (that happens in GenerateStockAnalysisJob).
     *
     * @return array<string, mixed>|null
     */
    private function analysisPayload(Stock $stock): ?array
    {
        $analysis = $stock->latestAiAnalysis()->first();

        $aiEnabled = app(AiTaskService::class)->isEnabled(AiTask::StockAnalysis);

        if ($analysis === null) {
            return null;
        }

        return [
            'signal' => $analysis->signal->value,
            'signal_label' => $analysis->signal->label(),
            'signal_color' => $analysis->signal->color(),
            'confidence' => $analysis->confidence,
            'horizon' => $analysis->horizon,
            'estimated_price_low' => $analysis->estimated_price_low,
            'estimated_price_high' => $analysis->estimated_price_high,
            'estimated_price' => $analysis->estimated_price,
            'currency' => $analysis->currency,
            'summary' => $analysis->summary,
            'drivers' => $analysis->drivers ?? [],
            'risks' => $analysis->risks ?? [],
            'disclaimer' => $analysis->disclaimer ?? StockAiAnalysis::DISCLAIMER,
            'generated_at' => $analysis->generated_at?->diffForHumans(),
            'is_stale' => $analysis->isStale() || ! $aiEnabled,
            'ai_enabled' => $aiEnabled,
        ];
    }

    /**
     * OHLC candle data for the chart (read from stored prices, JSON).
     */
    public function candles(Request $request, Stock $stock, ApiProviderRegistry $providers): JsonResponse
    {
        $timeframe = Timeframe::tryFrom($request->string('timeframe')->toString()) ?? Timeframe::FiveMinutes;
        $range = $this->normalizeChartRange($request->string('range')->toString());
        $rangeStart = $this->chartRangeStart($range);
        $apiActive = $providers->hasActiveRealProvider(ProviderType::MarketData);
        $hideSynthetic = $providers->shouldHideSyntheticMarketData();

        $candles = $stock->prices()
            ->timeframe($timeframe)
            ->withoutSyntheticWhenApiActive($hideSynthetic)
            ->when($rangeStart instanceof CarbonImmutable, fn (Builder $q) => $q->where('price_at', '>=', $rangeStart))
            ->latest('price_at')
            ->limit(self::CHART_RANGES[$range]['limit'])
            ->get(['open', 'high', 'low', 'close', 'volume', 'price_at', 'provider_key', 'source_kind'])
            ->sortBy('price_at')
            ->values()
            ->map(fn (StockPrice $price): array => $this->candlePayload($price))
            ->all();

        return response()->json([
            'symbol' => $stock->symbol,
            'timeframe' => $timeframe->value,
            'range' => $range,
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

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function chartRangeOptions(): array
    {
        return collect(self::CHART_RANGES)
            ->map(fn (array $range, string $value): array => [
                'value' => $value,
                'label' => $range['label'],
            ])
            ->values()
            ->all();
    }

    private function normalizeChartRange(string $range): string
    {
        if (array_key_exists($range, self::CHART_RANGES)) {
            return $range;
        }

        return self::DEFAULT_CHART_RANGE;
    }

    private function chartRangeStart(string $range): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();

        return match ($range) {
            '1h' => $now->subHour(),
            '3h' => $now->subHours(3),
            '24h' => $now->subDay(),
            '1mo' => $now->subMonth(),
            '3mo' => $now->subMonths(3),
            '5mo' => $now->subMonths(5),
            '1y' => $now->subYear(),
            '5y' => $now->subYears(5),
            default => null,
        };
    }
}
