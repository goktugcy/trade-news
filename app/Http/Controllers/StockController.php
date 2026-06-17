<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Enums\Timeframe;
use App\Models\Stock;
use App\Support\Presenters\NewsPresenter;
use App\Support\Presenters\StockPresenter;
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
            ->with(['stocks:id,symbol,market', 'source:id,name'])
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
    public function candles(Request $request, Stock $stock): JsonResponse
    {
        $timeframe = Timeframe::tryFrom($request->string('timeframe')->toString()) ?? Timeframe::FiveMinutes;

        $candles = $stock->prices()
            ->timeframe($timeframe)
            ->orderBy('price_at')
            ->limit(300)
            ->get(['open', 'high', 'low', 'close', 'volume', 'price_at'])
            ->map(fn ($c) => [
                'time' => $c->price_at->getTimestamp(),
                'open' => (float) $c->open,
                'high' => (float) $c->high,
                'low' => (float) $c->low,
                'close' => (float) $c->close,
                'volume' => (float) $c->volume,
            ]);

        return response()->json([
            'symbol' => $stock->symbol,
            'timeframe' => $timeframe->value,
            'candles' => $candles,
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
}
