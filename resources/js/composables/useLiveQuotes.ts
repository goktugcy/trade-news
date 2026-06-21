import { ref, type Ref } from 'vue';
import { useVisibilityAwarePoll } from '@/composables/useVisibilityAwarePoll';
import type { LiveQuote, LiveQuotesResponse, MarketStatusInfo, StockRow, TickerItem } from '@/types';

/**
 * Polls the shared live-quotes endpoint: per-symbol quotes plus the ticker,
 * top movers and market status. Drives in-place price updates (no reload).
 */
export function useLiveQuotes(symbols: () => string[], intervalMs = 15000): {
    quotes: Ref<Record<string, LiveQuote>>;
    ticker: Ref<TickerItem[] | null>;
    topMovers: Ref<{ gainers: StockRow[]; losers: StockRow[] } | null>;
    marketStatus: Ref<MarketStatusInfo[] | null>;
} {
    const quotes = ref<Record<string, LiveQuote>>({});
    const ticker = ref<TickerItem[] | null>(null);
    const topMovers = ref<{ gainers: StockRow[]; losers: StockRow[] } | null>(null);
    const marketStatus = ref<MarketStatusInfo[] | null>(null);

    async function poll(): Promise<void> {
        try {
            const params = new URLSearchParams();
            const list = symbols().filter(Boolean);
            if (list.length) {
                params.set('symbols', list.join(','));
            }

            const res = await fetch(`/stocks/live?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!res.ok) {
                return;
            }

            const json = (await res.json()) as LiveQuotesResponse;

            const map: Record<string, LiveQuote> = {};
            for (const quote of json.quotes ?? []) {
                map[quote.symbol] = quote;
            }
            quotes.value = map;
            ticker.value = json.ticker ?? null;
            topMovers.value = json.top_movers ?? null;
            marketStatus.value = json.market_status ?? null;
        } catch {
            // network hiccup — keep last known values
        }
    }

    useVisibilityAwarePoll(poll, intervalMs);

    return { quotes, ticker, topMovers, marketStatus };
}
