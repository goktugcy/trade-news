<?php

declare(strict_types=1);

use App\Enums\NotificationInterval;

it('exposes minutes equal to its backing value', function () {
    expect(NotificationInterval::FiveMinutes->minutes())->toBe(5)
        ->and(NotificationInterval::OneHour->minutes())->toBe(60)
        ->and(NotificationInterval::OneDay->minutes())->toBe(1440);
});

it('is due when minute-of-day is divisible by the interval', function () {
    // 10:30 → 630 minutes since midnight.
    $at = new DateTimeImmutable('2026-01-01 10:30:00');

    expect(NotificationInterval::FiveMinutes->isDueAt($at))->toBeTrue()   // 630 % 5 = 0
        ->and(NotificationInterval::FifteenMinutes->isDueAt($at))->toBeTrue() // 630 % 15 = 0
        ->and(NotificationInterval::OneHour->isDueAt($at))->toBeFalse();   // 630 % 60 = 30
});

it('fires the daily interval only at midnight', function () {
    expect(NotificationInterval::OneDay->isDueAt(new DateTimeImmutable('2026-01-01 00:00:00')))->toBeTrue()
        ->and(NotificationInterval::OneDay->isDueAt(new DateTimeImmutable('2026-01-01 00:05:00')))->toBeFalse();
});
