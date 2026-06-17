<?php

declare(strict_types=1);

namespace App\Enums;

enum Timeframe: string
{
    case OneMinute = '1m';
    case FiveMinutes = '5m';
    case FifteenMinutes = '15m';
    case OneHour = '1h';
    case OneDay = '1d';

    public function label(): string
    {
        return match ($this) {
            self::OneMinute => '1 minute',
            self::FiveMinutes => '5 minutes',
            self::FifteenMinutes => '15 minutes',
            self::OneHour => '1 hour',
            self::OneDay => '1 day',
        };
    }

    /**
     * Seconds covered by one candle of this timeframe.
     */
    public function seconds(): int
    {
        return match ($this) {
            self::OneMinute => 60,
            self::FiveMinutes => 300,
            self::FifteenMinutes => 900,
            self::OneHour => 3600,
            self::OneDay => 86400,
        };
    }

    public function isIntraday(): bool
    {
        return $this !== self::OneDay;
    }
}
