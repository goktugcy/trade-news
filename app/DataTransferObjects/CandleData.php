<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\Timeframe;
use Carbon\CarbonImmutable;

/**
 * A single OHLCV candle, normalized across providers.
 */
final readonly class CandleData
{
    public function __construct(
        public Timeframe $timeframe,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public float $volume,
        public CarbonImmutable $at,
    ) {}

    /**
     * Shape suitable for an upsert into the stock_prices table.
     *
     * @return array<string, mixed>
     */
    public function toRow(int $stockId): array
    {
        return [
            'stock_id' => $stockId,
            'timeframe' => $this->timeframe->value,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'volume' => $this->volume,
            'price_at' => $this->at->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ];
    }
}
