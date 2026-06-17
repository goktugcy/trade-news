<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\Models\Stock;
use App\Support\Presenters\StockPresenter;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the cached market-summary payloads (top movers) used by the dashboard
 * right rail. Computed once and shared across all users.
 */
class MarketSummaryService
{
    /**
     * @return array{gainers: array<int, mixed>, losers: array<int, mixed>}
     */
    public function topMovers(int $limit = 5): array
    {
        return Cache::remember('tn:top-movers', config('tradenews.cache.market_summary_ttl', 60), function () use ($limit): array {
            $rows = Stock::query()
                ->active()
                ->with('latestPrice')
                ->get()
                ->map(fn (Stock $stock) => StockPresenter::row($stock))
                ->filter(fn ($row) => $row['change_percent'] !== null)
                ->values();

            $sorted = $rows->sortByDesc('change_percent')->values();

            return [
                'gainers' => $sorted->take($limit)->all(),
                'losers' => $sorted->reverse()->take($limit)->values()->all(),
            ];
        });
    }
}
