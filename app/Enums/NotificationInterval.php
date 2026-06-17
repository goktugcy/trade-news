<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationInterval: int
{
    case FiveMinutes = 5;
    case FifteenMinutes = 15;
    case ThirtyMinutes = 30;
    case OneHour = 60;
    case ThreeHours = 180;
    case FiveHours = 300;
    case OneDay = 1440;

    /**
     * The interval in minutes (its backing value).
     */
    public function minutes(): int
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::FiveMinutes => 'Every 5 minutes',
            self::FifteenMinutes => 'Every 15 minutes',
            self::ThirtyMinutes => 'Every 30 minutes',
            self::OneHour => 'Every hour',
            self::ThreeHours => 'Every 3 hours',
            self::FiveHours => 'Every 5 hours',
            self::OneDay => 'Once a day',
        };
    }

    /**
     * Whether this interval is "due" for a scheduler tick that runs every
     * 5 minutes. We treat minutes-since-midnight modulo the interval as the
     * trigger so each cadence fires on aligned boundaries.
     */
    public function isDueAt(\DateTimeInterface $moment): bool
    {
        $minutesSinceMidnight = ((int) $moment->format('G') * 60) + (int) $moment->format('i');

        return $minutesSinceMidnight % $this->value === 0;
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $i) => [
            'value' => $i->value,
            'label' => $i->label(),
        ], self::cases());
    }
}
