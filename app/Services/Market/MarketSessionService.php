<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Enums\Market;
use App\Enums\MarketSession;
use Carbon\CarbonImmutable;

/**
 * Determines the current trading session for each exchange (open / closed /
 * pre-market / after-hours / holiday / weekend) and renders the open/close
 * times in any display timezone — so a user in Europe/Istanbul sees NASDAQ
 * hours in Istanbul local time. All instants are computed in the market's own
 * timezone; display strings are converted to the requested timezone.
 */
class MarketSessionService
{
    private const MAX_LOOKAHEAD_DAYS = 10;

    /**
     * @return array<string, mixed>
     */
    public function session(Market $market, ?string $displayTz = null, ?CarbonImmutable $now = null): array
    {
        $displayTz ??= $market->timezone();
        $now ??= CarbonImmutable::now($market->timezone());
        $now = $now->setTimezone($market->timezone());

        $hours = $market->tradingHours();
        $session = $this->resolveSession($market, $now);

        $regularOpen = $now->setTimeFromTimeString($hours['open']);
        $regularClose = $now->setTimeFromTimeString($hours['close']);

        return [
            'market' => $market->value,
            'label' => $market->label(),
            'session' => $session->value,
            'session_label' => $session->label(),
            'session_color' => $session->color(),
            'is_open' => $session->isOpen(),
            // Regular session bounds rendered in the viewer's timezone.
            'opens_at' => $regularOpen->setTimezone($displayTz)->format('H:i'),
            'closes_at' => $regularClose->setTimezone($displayTz)->format('H:i'),
            'display_timezone' => $displayTz,
            'market_timezone' => $market->timezone(),
            'local_time' => $now->format('H:i'),
            'next_open' => $this->nextOpen($market, $now)?->utc()->toIso8601String(),
            'next_close' => $session->isOpen() ? $regularClose->utc()->toIso8601String() : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $displayTz = null, ?CarbonImmutable $now = null): array
    {
        return array_map(fn (Market $m): array => $this->session($m, $displayTz, $now), Market::cases());
    }

    private function resolveSession(Market $market, CarbonImmutable $now): MarketSession
    {
        if ($this->isHoliday($market, $now)) {
            return MarketSession::Holiday;
        }

        if ($now->isWeekend()) {
            return MarketSession::Weekend;
        }

        $hours = $market->tradingHours();
        $open = $now->setTimeFromTimeString($hours['open']);
        $close = $now->setTimeFromTimeString($hours['close']);

        if ($now->betweenIncluded($open, $close)) {
            return MarketSession::Open;
        }

        $extended = $market->extendedHours();

        if ($extended !== null) {
            $preOpen = $now->setTimeFromTimeString($extended['pre']['open']);
            $preClose = $now->setTimeFromTimeString($extended['pre']['close']);
            $afterOpen = $now->setTimeFromTimeString($extended['after']['open']);
            $afterClose = $now->setTimeFromTimeString($extended['after']['close']);

            if ($now->betweenIncluded($preOpen, $preClose)) {
                return MarketSession::PreMarket;
            }

            if ($now->betweenIncluded($afterOpen, $afterClose)) {
                return MarketSession::AfterHours;
            }
        }

        return MarketSession::Closed;
    }

    public function isHoliday(Market $market, CarbonImmutable $moment): bool
    {
        $holidays = (array) config("tradenews.market_holidays.{$market->value}", []);

        return in_array($moment->format('Y-m-d'), $holidays, true);
    }

    /**
     * The next regular-session open instant (today if still upcoming, otherwise
     * the next weekday that is not a holiday).
     */
    private function nextOpen(Market $market, CarbonImmutable $now): ?CarbonImmutable
    {
        $hours = $market->tradingHours();
        $candidate = $now;

        for ($i = 0; $i <= self::MAX_LOOKAHEAD_DAYS; $i++) {
            $day = $candidate->setTimeFromTimeString($hours['open']);
            $tradable = ! $day->isWeekend() && ! $this->isHoliday($market, $day);

            if ($tradable && ($i > 0 || $now->lt($day))) {
                return $day;
            }

            $candidate = $candidate->addDay()->startOfDay();
        }

        return null;
    }
}
