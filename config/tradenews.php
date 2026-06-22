<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Market data provider
    |--------------------------------------------------------------------------
    |
    | Which driver the MarketDataProviderInterface resolves to. "synthetic"
    | generates realistic OHLC data with no API key so the platform runs out
    | of the box. Switch to "finnhub" or "twelvedata" once a key is set.
    |
    */

    'market_data' => [
        'default' => env('MARKET_DATA_PROVIDER', 'synthetic'),

        // A stock priced within this many minutes is considered "fresh" and is
        // skipped by the price fetcher, so the fetch budget goes to stocks that
        // haven't been updated yet (no provider re-fetches a fresh symbol).
        'fresh_within_minutes' => (int) env('PRICE_FRESH_WITHIN_MINUTES', 10),

        'providers' => [
            'finnhub' => [
                'key' => env('FINNHUB_KEY'),
                'base_url' => 'https://finnhub.io/api/v1',
                'candles_enabled' => env('FINNHUB_CANDLES_ENABLED', false),
                'rate_limit_per_minute' => env('FINNHUB_RATE_LIMIT_PER_MINUTE', 50),
            ],
            'twelvedata' => [
                'key' => env('TWELVEDATA_KEY'),
                'base_url' => 'https://api.twelvedata.com',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | News provider
    |--------------------------------------------------------------------------
    */

    'news' => [
        'default' => env('NEWS_PROVIDER', 'synthetic'),

        'providers' => [
            'finnhub' => [
                'key' => env('FINNHUB_KEY'),
                'base_url' => 'https://finnhub.io/api/v1',
            ],
            'kap' => [
                'base_url' => env('KAP_BASE_URL', 'https://www.kap.org.tr'),
            ],

            /*
            | RSS / Atom feeds aggregated by RssNewsProvider. Each feed has its
            | own news_sources.key so every original source is tracked, even
            | after cross-source duplicates are merged. `market` scopes the feed
            | (BIST = Turkish, NASDAQ = global; null also treated as global).
            | Feed URLs change over time — edit here, no code change needed.
            */
            'rss' => [
                'timeout' => 12,
                'per_feed_limit' => 40,
                'feeds' => [
                    // Global markets
                    ['key' => 'reuters', 'name' => 'Reuters', 'market' => 'NASDAQ',
                        'url' => 'https://www.reutersagency.com/feed/?best-topics=business-finance&post_type=best',
                        'homepage_url' => 'https://www.reuters.com'],
                    ['key' => 'marketwatch', 'name' => 'MarketWatch', 'market' => 'NASDAQ',
                        'url' => 'https://feeds.content.dowjones.io/public/rss/mw_topstories',
                        'homepage_url' => 'https://www.marketwatch.com'],
                    ['key' => 'yahoo-finance', 'name' => 'Yahoo Finance', 'market' => 'NASDAQ',
                        'url' => 'https://finance.yahoo.com/news/rssindex',
                        'homepage_url' => 'https://finance.yahoo.com'],
                    ['key' => 'cnbc', 'name' => 'CNBC', 'market' => 'NASDAQ',
                        'url' => 'https://www.cnbc.com/id/100003114/device/rss/rss.html',
                        'homepage_url' => 'https://www.cnbc.com'],
                    ['key' => 'investing-com', 'name' => 'Investing.com', 'market' => null,
                        'url' => 'https://www.investing.com/rss/news.rss',
                        'homepage_url' => 'https://www.investing.com'],

                    // Turkish market (BIST)
                    ['key' => 'kap-rss', 'name' => 'KAP', 'market' => 'BIST',
                        'url' => env('KAP_RSS_URL', ''),
                        'homepage_url' => 'https://www.kap.org.tr'],
                    ['key' => 'bloomberg-ht', 'name' => 'Bloomberg HT', 'market' => 'BIST',
                        'url' => 'https://www.bloomberght.com/rss',
                        'homepage_url' => 'https://www.bloomberght.com'],
                    ['key' => 'foreks', 'name' => 'Foreks', 'market' => 'BIST',
                        'url' => env('FOREKS_RSS_URL', ''),
                        'homepage_url' => 'https://www.foreks.com'],
                    ['key' => 'investing-tr', 'name' => 'Investing Türkiye', 'market' => 'BIST',
                        'url' => 'https://tr.investing.com/rss/news.rss',
                        'homepage_url' => 'https://tr.investing.com'],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI summaries
    |--------------------------------------------------------------------------
    |
    | Optional LLM-generated article summaries. With no API key the platform
    | falls back to the article's own summary — nothing breaks.
    |
    */

    'ai' => [
        'enabled' => (bool) env('OPENAI_API_KEY'),
        'provider' => env('AI_PROVIDER', 'openai'),
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'rate_limit_per_minute' => env('AI_RATE_LIMIT_PER_MINUTE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stooq historical data (daily OHLC import for NASDAQ charts)
    |--------------------------------------------------------------------------
    */

    'stooq' => [
        'base_url' => env('STOOQ_BASE_URL', 'https://stooq.com'),
        'timeout' => env('STOOQ_TIMEOUT', 30),
        'history_years' => env('STOOQ_HISTORY_YEARS', 6),
        'rate_limit_per_minute' => env('STOOQ_RATE_LIMIT_PER_MINUTE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram bot
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Data synchronization (Financial Modeling Prep — NASDAQ list + profiles)
    |--------------------------------------------------------------------------
    |
    | FMP supplies the NASDAQ universe + company profiles/metadata. Quotes and
    | candles stay on the market-data providers. Without a key, the list sync
    | falls back to the existing Finnhub-based command.
    |
    */

    'sync' => [
        'fmp' => [
            'key' => env('FMP_API_KEY'),
            'base_url' => env('FMP_BASE_URL', 'https://financialmodelingprep.com/stable'),
            'exchange' => env('FMP_EXCHANGE', 'NASDAQ'),
            'profile_batch' => (int) env('FMP_PROFILE_BATCH', 50),
            'rate_limit_per_minute' => (int) env('FMP_RATE_LIMIT_PER_MINUTE', 250),
            'profile_ttl_days' => (int) env('FMP_PROFILE_TTL_DAYS', 30),
        ],

        'us_universe' => [
            'source' => env('US_INDEX_UNIVERSE_SOURCE', 'auto'), // auto | fmp | fallback
            'nasdaq100_etf' => env('US_INDEX_UNIVERSE_NASDAQ100_ETF', 'QQQ'),
            'cache_ttl_seconds' => (int) env('US_INDEX_UNIVERSE_CACHE_TTL_SECONDS', 43200),
            'min_sp500_symbols' => (int) env('US_INDEX_UNIVERSE_MIN_SP500_SYMBOLS', 400),
            'min_nasdaq100_symbols' => (int) env('US_INDEX_UNIVERSE_MIN_NASDAQ100_SYMBOLS', 80),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider health state machine (consecutive request thresholds)
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'health' => [
            'degraded_after' => (int) env('PROVIDER_DEGRADED_AFTER', 2),
            'down_after' => (int) env('PROVIDER_DOWN_AFTER', 4),
            'recover_after' => (int) env('PROVIDER_RECOVER_AFTER', 2),
        ],
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', 'change-me'),
        'api_url' => 'https://api.telegram.org',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTLs (seconds)
    |--------------------------------------------------------------------------
    |
    | Frequently-read, centrally-fetched data is cached so user requests never
    | hit an external API directly.
    |
    */

    'cache' => [
        'quote_ttl' => 360,         // latest price per stock
        'feed_ttl' => 30,           // rendered news feed pages
        'market_summary_ttl' => 60, // top movers / market status
    ],

    /*
    |--------------------------------------------------------------------------
    | Data retention (days) used by CleanupOldDataJob
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'intraday_prices_days' => 30,
        'news_days' => 120,
        'notifications_days' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Market holiday calendars (YYYY-MM-DD, in the market's own timezone)
    |--------------------------------------------------------------------------
    |
    | Used by MarketSessionService to report a market as "holiday". These are
    | static lists and should be refreshed yearly. Add half-days as needed.
    |
    */

    'market_holidays' => [
        'NASDAQ' => [
            '2026-01-01', '2026-01-19', '2026-02-16', '2026-04-03',
            '2026-05-25', '2026-06-19', '2026-07-03', '2026-09-07',
            '2026-11-26', '2026-12-25',
        ],
        'BIST' => [
            '2026-01-01', '2026-03-20', '2026-03-21', '2026-03-22', '2026-03-23',
            '2026-04-23', '2026-05-01', '2026-05-19', '2026-05-27', '2026-05-28',
            '2026-05-29', '2026-05-30', '2026-07-15', '2026-08-30', '2026-10-29',
        ],
    ],
];
