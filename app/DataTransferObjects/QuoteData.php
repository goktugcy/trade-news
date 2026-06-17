<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * A point-in-time quote for a stock, normalized across providers.
 */
final readonly class QuoteData
{
    public function __construct(
        public string $symbol,
        public float $price,
        public float $open,
        public float $high,
        public float $low,
        public float $previousClose,
        public float $volume,
        public CarbonImmutable $at,
    ) {}

    public function change(): float
    {
        return round($this->price - $this->previousClose, 4);
    }

    public function changePercent(): float
    {
        if ($this->previousClose == 0.0) {
            return 0.0;
        }

        return round((($this->price - $this->previousClose) / $this->previousClose) * 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'price' => $this->price,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'previous_close' => $this->previousClose,
            'volume' => $this->volume,
            'change' => $this->change(),
            'change_percent' => $this->changePercent(),
            'at' => $this->at->toIso8601String(),
        ];
    }
}
