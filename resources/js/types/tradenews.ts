export type Market = 'BIST' | 'NASDAQ';
export type SentimentValue = 'positive' | 'neutral' | 'negative';

export type StockRef = {
    id: number;
    symbol: string;
    market: Market;
};

export type NewsCardData = {
    id: number;
    title: string;
    summary: string | null;
    url: string | null;
    image_url: string | null;
    market: Market | null;
    sentiment: SentimentValue | null;
    sentiment_color: string | null;
    importance: number;
    published_at: string | null;
    published_for_humans: string | null;
    source: string | null;
    stocks: StockRef[];
};

export type StockRow = {
    id: number;
    symbol: string;
    name: string;
    market: Market;
    exchange: string | null;
    currency: string;
    sector: string | null;
    is_active: boolean;
    price: number | null;
    change: number | null;
    change_percent: number | null;
    quote_at: string | null;
    in_watchlist?: boolean;
    alerts_enabled?: boolean;
    watchlist_id?: number;
};

export type Candle = {
    time: number;
    open: number;
    high: number;
    low: number;
    close: number;
    volume: number;
};

export type MarketStatusInfo = {
    market: Market;
    label: string;
    is_open: boolean;
    local_time: string;
    timezone: string;
    opens_at: string;
    closes_at: string;
};

export type PaginatedNews = {
    data: NewsCardData[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
};

export type SelectOption = { value: string | number; label: string; [key: string]: unknown };
