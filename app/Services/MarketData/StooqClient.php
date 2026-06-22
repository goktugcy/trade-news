<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\Enums\Market;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads daily OHLC history for a stock from stooq.com's CSV endpoint
 * (https://stooq.com/q/d/l/?s=aapl.us&i=d). NASDAQ symbols map to the `.us`
 * suffix. Returns the raw CSV body, or null on failure / "no data".
 */
class StooqClient
{
    public function fetchDailyCsv(Stock $stock, ?CarbonImmutable $since = null): ?string
    {
        $symbol = $this->stooqSymbol($stock);

        if ($symbol === null) {
            return null;
        }

        $query = ['s' => $symbol, 'i' => 'd'];

        if ($since instanceof CarbonImmutable) {
            $query['d1'] = $since->format('Ymd');
            $query['d2'] = CarbonImmutable::now()->format('Ymd');
        }

        $base = rtrim((string) config('tradenews.stooq.base_url', 'https://stooq.com'), '/');

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; trade-news/1.0; +https://stooq.com)',
                'Accept' => 'text/csv,text/plain,*/*',
            ])
                ->connectTimeout(10)
                ->timeout((int) config('tradenews.stooq.timeout', 30))
                ->retry(2, 750, throw: false)
                ->get("{$base}/q/d/l/", $query);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $body = trim($response->body());

        // Stooq returns "No data" (or an HTML error page) when nothing is found.
        if ($body === '' || Str::startsWith($body, '<') || ! Str::contains(Str::lower($body), 'date,')) {
            return null;
        }

        return $body;
    }

    /**
     * Map a stock to its stooq ticker. Only NASDAQ (`.us`) is supported for now.
     */
    public function stooqSymbol(Stock $stock): ?string
    {
        if ($stock->market !== Market::NASDAQ) {
            return null;
        }

        // stooq uses lowercase and dashes (e.g. BRK.B -> brk-b.us).
        $symbol = Str::of($stock->symbol)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->toString();

        return $symbol === '' ? null : "{$symbol}.us";
    }
}
