<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Market;
use Carbon\CarbonImmutable;

/**
 * Computes whether a market is currently open, based on its local trading hours.
 */
class MarketStatus
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Market $market): array
    {
        $now = CarbonImmutable::now($market->timezone());
        $hours = $market->tradingHours();

        $open = $now->setTimeFromTimeString($hours['open']);
        $close = $now->setTimeFromTimeString($hours['close']);

        $isWeekday = $now->isWeekday();
        $isOpen = $isWeekday && $now->betweenIncluded($open, $close);

        return [
            'market' => $market->value,
            'label' => $market->label(),
            'is_open' => $isOpen,
            'local_time' => $now->format('H:i'),
            'timezone' => $market->timezone(),
            'opens_at' => $hours['open'],
            'closes_at' => $hours['close'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return array_map(self::for(...), Market::cases());
    }
}
