<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\DataTransferObjects\CandleData;
use App\DataTransferObjects\QuoteData;
use App\Enums\Timeframe;
use App\Models\Stock;

interface MarketDataProviderInterface
{
    /**
     * A stable key identifying this provider (matches api_providers.key).
     */
    public function key(): string;

    /**
     * Latest quote for a stock, or null if unavailable.
     */
    public function getQuote(Stock $stock): ?QuoteData;

    /**
     * Recent OHLCV candles for a stock, oldest → newest.
     *
     * @return array<int, CandleData>
     */
    public function getCandles(Stock $stock, Timeframe $timeframe, int $limit = 120): array;
}
