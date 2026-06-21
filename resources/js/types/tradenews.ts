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
    has_ai_summary: boolean;
    has_translation: boolean;
    translation_locale: 'en' | 'tr' | null;
    translation_status: 'translated' | 'translating' | 'original';
    url: string | null;
    image_url: string | null;
    market: Market | null;
    sentiment: SentimentValue | null;
    sentiment_color: string | null;
    importance: number;
    published_at: string | null;
    published_for_humans: string | null;
    source: string | null;
    source_count: number;
    sources: { name: string | null; url: string | null }[];
    stocks: StockRef[];
    reaction: 1 | -1 | null;
    is_saved: boolean;
    like_count: number;
    dislike_count: number;
};

export type NewsSourcePref = {
    id: number;
    name: string;
    language: string | null;
    enabled: boolean;
};

export type StockRow = {
    id: number;
    symbol: string;
    name: string;
    market: Market;
    exchange: string | null;
    currency: string;
    sector: string | null;
    industry?: string | null;
    market_cap?: number | null;
    website?: string | null;
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
    provider_key: string | null;
    source_kind: 'candle' | 'quote' | 'synthetic';
};

export type DataPreferences = {
    auto_refresh_seconds: number;
    preferred_markets: Market[] | null;
};

export type OnboardingState = {
    completed: boolean;
    should_show: boolean;
    completed_at: string | null;
};

export type MarketSessionValue = 'open' | 'closed' | 'pre_market' | 'after_hours' | 'holiday' | 'weekend';

export type MarketStatusInfo = {
    market: Market;
    label: string;
    session: MarketSessionValue;
    session_label: string;
    session_color: string;
    is_open: boolean;
    opens_at: string;
    closes_at: string;
    display_timezone: string;
    market_timezone: string;
    local_time: string;
    next_open: string | null;
    next_close: string | null;
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

export type TickerItem = {
    symbol: string;
    market: Market;
    price: number | null;
    currency: string;
    change_percent: number | null;
};

export type NewsFeedScope = 'all' | 'watchlist' | 'saved';

export type LiveNewsResponse = {
    items: NewsCardData[];
    updates: NewsCardData[];
    latest_id: number;
};

export type LiveQuote = {
    symbol: string;
    price: number | null;
    change: number | null;
    change_percent: number | null;
    quote_at: string | null;
};

export type LiveQuotesResponse = {
    quotes: LiveQuote[];
    ticker: TickerItem[];
    top_movers: { gainers: StockRow[]; losers: StockRow[] };
    market_status: MarketStatusInfo[];
};
