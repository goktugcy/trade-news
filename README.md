# TradeNews

A SaaS-style **market news & stock tracking** platform, built as a clean **modular Laravel monolith**.
Track BIST & NASDAQ stocks, view current/historical prices with charts, follow market and
company-specific news, build watchlists, and receive **Telegram alerts** on your chosen schedule.

The interface is a modern financial terminal crossed with a clean social feed: left sidebar
navigation, a central news feed, and a right-hand market summary / watchlist rail, with full
light/dark mode.

---

## Tech stack

| Layer | Choice |
| --- | --- |
| Framework | Laravel 13 (PHP 8.3+) |
| Database | PostgreSQL (uses `jsonb` + `ILIKE`) |
| Cache / Queue | Laravel Cache + Queue (`database` driver by default; Redis-ready) |
| Scheduler | Laravel Scheduler (`schedule:work`) |
| Frontend | Inertia.js + Vue 3 + TypeScript |
| Styling | Tailwind CSS v4 (shadcn-vue / reka-ui components) |
| Charts | [TradingView Lightweight Charts](https://github.com/tradingview/lightweight-charts) |
| Auth | Laravel Fortify (incl. 2FA + passkeys from the starter kit) |
| Notifications | Telegram Bot API (via Laravel's HTTP client — no extra SDK) |
| Tests | Pest 4 |

> **Admin panel note:** The spec suggested Filament. To keep a single coherent Inertia/Vue
> asset pipeline (and avoid bolting a separate Livewire stack onto Laravel 13), the admin panel
> is implemented as a **native Inertia admin section** at `/admin`, gated by an `is_admin`
> policy/middleware. It manages stocks, news sources, API providers, users, jobs, failed jobs,
> notification logs and system health. See [Swapping in Filament](#optional-swapping-in-filament).

> **Redis / Horizon note:** Cache + queue default to the `database` driver so the app runs with
> no Redis daemon. To use Redis + Horizon, see [Scaling with Redis & Horizon](#optional-scaling-with-redis--horizon).

---

## Architecture overview

> **Golden rule:** the system **never fetches third-party API data per user request**.
> Scheduled jobs fetch prices & news *centrally*, store them in PostgreSQL, and cache hot data.
> User requests only ever read from the DB/cache. Notifications are filtered *per user* from the
> already-stored data based on watchlists + rules.

```
                ┌──────────────── Laravel Scheduler (every 5 min) ─────────────────┐
                │                                                                  │
   tradenews:fetch-prices        tradenews:fetch-news        tradenews:dispatch-notifications
                │                          │                                 │
        FetchStockPricesJob        FetchMarketNewsJob                 NotificationDispatcher
        (per active stock)                 │                          (per due rule, per user)
                │              ┌────────────┴───────────┐                     │
     MarketDataProvider        CalculateNewsSentimentJob │             SendTelegramNotificationJob
   (synthetic|finnhub|twelve)  MatchNewsWithStocksJob ───┘                     │
                │                          │                              Telegram Bot API
          stock_prices              news_items + news_stock_matches
                │                          │
                └──────────► PostgreSQL + Cache ◄──────────┘
                                   │
                          Inertia controllers (read-only)
                                   │
                            Vue 3 dashboard / feeds / charts
```

### Service layout (`app/Services`)

```
Services/
  MarketData/
    MarketDataProviderInterface.php     # contract: getQuote(), getCandles()
    SyntheticMarketDataProvider.php     # default — realistic data, no API key
    FinnhubProvider.php                 # real example integration
    TwelveDataProvider.php              # real example integration
    MarketDataIngestor.php              # central fetch → upsert candles → cache quote
    MarketSummaryService.php            # cached top movers
  News/
    NewsProviderInterface.php           # contract: fetchLatest()
    SyntheticNewsProvider.php           # default — generates matchable headlines
    FinnhubNewsProvider.php             # real example integration
    KapNewsProvider.php                 # best-effort KAP (BIST disclosures)
    NewsIngestor.php                    # dedupe (hash) → store
    NewsMatcherService.php              # match news → stocks (symbol/name/alias/keyword)
    SentimentAnalyzer.php               # lexicon sentiment + importance scoring
  Telegram/
    TelegramBotService.php              # sendMessage / setWebhook / format alert
    TelegramConnectionService.php       # connection-code → chat_id linking
  Notification/
    NotificationDispatcher.php          # per-user filtering + queueing of alerts
```

Providers are bound to their interface in `App\Providers\TradeNewsServiceProvider` based on
`config/tradenews.php` (driven by `.env`). Swapping `MARKET_DATA_PROVIDER=finnhub` is all it
takes to switch data sources — no other code changes.

### Domain model

`stocks`, `stock_prices`, `news_sources`, `news_items`, `news_stock_matches`, `watchlists`,
`telegram_integrations`, `notification_rules`, `app_notifications` (delivery log), `api_providers`,
`system_jobs` (scheduled-run heartbeat), plus Laravel's `jobs` / `failed_jobs`.

Enums (`app/Enums`): `Market`, `NotificationInterval`, `Sentiment`, `ProviderStatus`,
`ProviderType`, `Timeframe`. DTOs (`app/DataTransferObjects`): `QuoteData`, `CandleData`,
`NewsItemData`.

---

## Requirements

- PHP 8.3+
- Composer 2
- Node 20+ / npm
- PostgreSQL 14+
- (optional) Redis, for Horizon / Reverb

---

## Setup

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Create the database (defaults assume a local 'tradenews' DB)
createdb tradenews            # or: psql -c "CREATE DATABASE tradenews;"

# 4. Migrate + seed demo data (BIST/NASDAQ stocks, prices, matched news, demo users)
php artisan migrate --seed

# 5. Build the frontend
npm run build                 # or `npm run dev` for HMR
```

Then run everything (server + queue worker + scheduler + Vite) — the starter kit ships a combined
dev script:

```bash
composer run dev
```

…or run the pieces individually:

```bash
php artisan serve
php artisan queue:work          # processes FetchStockPrices / News / Telegram jobs
php artisan schedule:work       # fires the central fetchers every 5 minutes
npm run dev
```

### Demo accounts (seeded)

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@tradenews.test` | `password` |
| User | `demo@tradenews.test` | `password` |

The demo user comes with a 5-stock watchlist and a 15-minute alert rule (Telegram starts
unconnected so you can walk through the connect flow).

---

## Environment keys (`.env`)

```dotenv
DB_CONNECTION=pgsql
DB_DATABASE=tradenews

# Providers: "synthetic" runs with no keys. Switch to a real driver once you add a key.
MARKET_DATA_PROVIDER=synthetic       # synthetic | finnhub | twelvedata
NEWS_PROVIDER=synthetic              # synthetic | finnhub | kap
FINNHUB_KEY=
TWELVEDATA_KEY=

# Telegram
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
TELEGRAM_WEBHOOK_SECRET=change-me
```

All tunables (cache TTLs, retention windows) live in `config/tradenews.php`.

---

## Scheduled commands

Registered in `routes/console.php` (run with `php artisan schedule:work`):

| Command | Cadence | Purpose |
| --- | --- | --- |
| `tradenews:fetch-prices` | every 5 min | dispatch a price-fetch job per active stock |
| `tradenews:fetch-news` | every 5 min | fetch + dedupe news, chain sentiment + matching |
| `tradenews:match-news` | every 10 min | safety-net match sweep for unmatched items |
| `tradenews:dispatch-notifications` | every 5 min | queue Telegram alerts for rules due this minute |
| `tradenews:check-providers` | every 30 min | probe provider health → `api_providers` |
| `tradenews:cleanup` | daily 03:30 | prune old prices/news/notifications + dedupe |

Run any once manually, e.g. `php artisan tradenews:fetch-news`.

Notification cadence: the dispatcher runs every 5 minutes and itself decides which user rules
(5m / 15m / 30m / 1h / 3h / 5h / 1d) are *due* based on the minute-of-day, then sends only news
the user hasn't already been alerted about.

---

## Telegram setup

1. Create a bot with [@BotFather](https://t.me/BotFather); set `TELEGRAM_BOT_TOKEN` and
   `TELEGRAM_BOT_USERNAME`.
2. Point the webhook at your app (the URL embeds your shared secret):
   ```bash
   php artisan tradenews:telegram-set-webhook https://your-domain/telegram/webhook/<secret>
   ```
   (locally, expose your app with a tunnel such as ngrok first.)
3. In the app: **Settings → Telegram → Generate code**, then send that code (or `/start <code>`)
   to your bot. The webhook stores your `chat_id` and enables alerts.

---

## Tests

The suite runs against a dedicated Postgres database (the app relies on `jsonb` + `ILIKE`):

```bash
createdb tradenews_test
php artisan test
```

Covers: news↔stock matching, sentiment scoring, notification-interval scheduling, watchlist CRUD
+ authorization, news feed filtering, stock detail / candle JSON / search, notification-rule
CRUD + policies, the Telegram connect flow, per-user notification dispatch + delivery, and admin
access control.

```bash
composer test          # pint + phpstan + pest
```

---

## Optional: scaling with Redis & Horizon

The app is Redis-ready. To switch:

```dotenv
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan horizon          # instead of queue:work
```

Cache reads already go through Laravel's `Cache` facade, so quotes/top-movers transparently move
to Redis.

## Optional: swapping in Filament

If you prefer Filament for admin:

```bash
composer require filament/filament
php artisan filament:install --panels
php artisan make:filament-resource Stock --generate
# …repeat for NewsSource, ApiProvider, User, Notification
```

The existing models, enums and casts are Filament-friendly; you can retire the
`app/Http/Controllers/Admin/*` controllers and `routes/admin.php` once resources exist.

---

## Project structure (high level)

```
app/
  Console/Commands/        scheduled commands
  DataTransferObjects/     QuoteData, CandleData, NewsItemData
  Enums/                   Market, NotificationInterval, Sentiment, ...
  Http/Controllers/        user-facing + Admin/ + Webhooks/
  Http/Requests/           form-request validation
  Jobs/                    Fetch*, MatchNews, Sentiment, SendTelegram, Cleanup
  Models/                  Eloquent models
  Policies/                WatchlistPolicy, NotificationRulePolicy
  Providers/               TradeNewsServiceProvider (bindings + admin gate)
  Services/                MarketData / News / Telegram / Notification
  Support/                 presenters + MarketStatus
config/tradenews.php       providers, telegram, cache TTLs, retention
database/migrations        domain schema
database/seeders           StockSeeder, ApiProviderSeeder, DemoContentSeeder
resources/js/
  components/tradenews/     NewsCard, StockChart, badges, panels, AdminNav
  pages/                    Dashboard, news/, stocks/, watchlist/, alerts/, settings/, admin/
routes/                    web.php, settings.php, admin.php, console.php
tests/                     Pest feature + unit
```
