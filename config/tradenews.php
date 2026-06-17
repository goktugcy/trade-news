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

        'providers' => [
            'finnhub' => [
                'key' => env('FINNHUB_KEY'),
                'base_url' => 'https://finnhub.io/api/v1',
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
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram bot
    |--------------------------------------------------------------------------
    */

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
        'quote_ttl' => 60,          // latest price per stock
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
];
