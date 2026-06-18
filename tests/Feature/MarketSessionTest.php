<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\MarketSession;
use App\Services\Market\MarketSessionService;
use Carbon\CarbonImmutable;

function sessionAt(string $iso, string $tz = 'America/New_York'): array
{
    return app(MarketSessionService::class)->session(
        Market::NASDAQ,
        'Europe/Istanbul',
        CarbonImmutable::parse($iso, $tz),
    );
}

it('reports NASDAQ open during regular hours on a weekday', function () {
    expect(sessionAt('2026-06-17 10:00:00')['session'])->toBe(MarketSession::Open->value);
});

it('reports pre-market and after-hours windows', function () {
    expect(sessionAt('2026-06-17 05:00:00')['session'])->toBe(MarketSession::PreMarket->value)
        ->and(sessionAt('2026-06-17 17:00:00')['session'])->toBe(MarketSession::AfterHours->value);
});

it('reports weekend and holiday closures', function () {
    expect(sessionAt('2026-06-20 12:00:00')['session'])->toBe(MarketSession::Weekend->value)   // Saturday
        ->and(sessionAt('2026-06-19 12:00:00')['session'])->toBe(MarketSession::Holiday->value); // Juneteenth
});

it('renders NASDAQ open/close in the viewer timezone', function () {
    // 09:30 EDT (UTC-4) → 16:30 in Europe/Istanbul (UTC+3).
    $session = sessionAt('2026-06-17 10:00:00');

    expect($session['opens_at'])->toBe('16:30')
        ->and($session['display_timezone'])->toBe('Europe/Istanbul');
});
