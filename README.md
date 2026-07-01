# TradeNews

A SaaS-style **market news & stock tracking** platform, built as a clean **modular Laravel monolith**.
It tracks **NASDAQ-100 and S&P 500** stocks with news aggregation, AI summaries, FinBERT sentiment,
an **AI Outlook** (positive/neutral/negative — not buy/sell), watchlists, stock detail pages with
**TradingView charts**, latest quotes, alerts, **in-app notifications** and optional **Telegram
notifications**, plus an admin section for provider/sync monitoring.

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
| Charts | TradingView embed widget (data + charting; no internal OHLCV sync by default) |
| Market data | FMP batch quotes (NASDAQ-100 + S&P 500 universe, profiles, latest quotes); Finnhub/TwelveData optional |
| News feeds | RSS/Atom via `laminas/laminas-feed` (5 sources) + Finnhub |
| AI summaries | OpenAI (optional, via Laravel's HTTP client — no SDK; graceful no-key fallback) |
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
                ┌──────────────── Laravel Scheduler (every minute) ────────────────┐
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
    FmpQuoteProvider.php                # FMP quote mapper (primary quote source)
    FmpQuoteSyncService.php             # batch quote sync for index members only
    FinnhubProvider.php / TwelveDataProvider.php  # optional integrations
    MarketDataIngestor.php              # central fetch → cache quote (+ candles if enabled)
    MarketSummaryService.php            # cached top movers
  Sync/
    FmpClient.php                       # FMP HTTP client: stock-list, profile, batch-quote
    NasdaqSyncService.php               # NASDAQ-100 + S&P 500 universe + profile sync
  News/
    NewsIngestor.php                    # dedupe (hash) → store
    StockAliasService.php               # deterministic alias index + text → stock matching
    NewsMatcherService.php              # match news → stocks via the alias index
    NewsSentimentService.php            # FinBERT sentiment (lexicon fallback) on English text
  Ai/
    StockAnalyzer.php                   # AI Outlook (positive/neutral/negative, no price target)
  Alerts/
    AlertEvaluator.php                  # price/%-change/volume/news alerts + cooldown
  Telegram/
    TelegramBotService.php              # sendMessage / setWebhook / format alert
  Notification/
    NotificationCenter.php              # toUser / toAdmins (in-app + optional Telegram)
    NotificationDispatcher.php          # per-user filtering + queueing of alerts
```

> **News pipeline order:** financial analysis runs on the **original English** text *before*
> translation — ingest → dedupe → alias matching → FinBERT sentiment → AI summary/outlook →
> importance → (on-demand) Turkish translation. Translation never precedes sentiment/analysis.

Providers are bound to their interface in `App\Providers\TradeNewsServiceProvider` based on
`config/tradenews.php` (driven by `.env`). Swapping `MARKET_DATA_PROVIDER=finnhub` is all it
takes to switch data sources — no other code changes.

### Domain model

`stocks`, `stock_prices`, `news_sources`, `news_items`, `news_stock_matches`, `watchlists`,
`telegram_integrations`, `notification_rules`, `app_notifications` (delivery log), `api_providers`,
`system_jobs` (scheduled-run heartbeat), plus Laravel's `jobs` / `failed_jobs`.

Plus `stock_aliases` (deterministic matching index), `stock_alerts` (with `trigger_count`),
`stock_ai_analyses`, `stock_index_memberships` (nasdaq100 / sp500), `user_notifications`,
`provider_events` and `sync_runs`.

Enums (`app/Enums`): `Market`, `MarketSession`, `StockIndex`, `AlertType`, `Sentiment`,
`StockSignal` (AI Outlook), `NotificationCategory`, `NotificationInterval`, `ProviderStatus`,
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

# 4. Migrate + seed demo data (NASDAQ stocks, prices, matched news, demo users)
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

The demo user comes with a 4-stock watchlist and a 15-minute alert rule (Telegram starts
unconnected so you can walk through the connect flow).

---

## Environment keys (`.env`)

```dotenv
DB_CONNECTION=pgsql
DB_DATABASE=tradenews

# Providers: "synthetic" runs with no keys. Switch to a real driver once you add a key.
MARKET_DATA_PROVIDER=synthetic       # synthetic | finnhub | twelvedata
NEWS_PROVIDER=synthetic              # synthetic | finnhub
FINNHUB_KEY=
TWELVEDATA_KEY=

# Quotes: defaults to "fmp" when FMP_API_KEY is set, else "synthetic".
QUOTE_PROVIDER=
FMP_API_KEY=                         # NASDAQ-100 + S&P 500 universe, profiles, batch quotes
FMP_QUOTE_BATCH=100                  # symbols per /batch-quote request
# Charts come from TradingView; internal historical OHLCV sync stays off by default.
STOCK_HISTORICAL_OHLCV_ENABLED=false

# AI summaries (optional — no key = falls back to the article's own summary)
AI_PROVIDER=openai
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4o-mini

# Telegram
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
TELEGRAM_WEBHOOK_SECRET=change-me
```

All tunables (RSS feed URLs, market holidays, cache TTLs, retention windows) live in
`config/tradenews.php`. News providers, refresh intervals and fetch limits are managed at runtime
in the `api_providers` table (admin panel).

### RSS aggregation, timezones & AI summaries

- **RSS news aggregation** — `RssNewsProvider` (built on `laminas/laminas-feed`) pulls 5 sources
  (Reuters, MarketWatch, Yahoo Finance, CNBC and Investing.com), configured under
  `tradenews.news.providers.rss.feeds`. Each feed is its own
  `news_sources` row. `tradenews:fetch-news` fans out one queued job **per active provider**, so
  all sources contribute (not just the first).
- **Cross-source merge** — the same story from multiple outlets collapses into one article
  (`TitleNormalizer` fingerprint + ±48h window), tracked in `news_item_sources` with a
  `source_count`. The feed shows “+N more” and corroboration nudges importance up.
- **Per-user timezone** — set in **Settings → Profile** (default `Europe/Istanbul`). Timestamps are
  stored UTC and rendered in the user's zone client-side (`resources/js/lib/date.ts`). Market
  open/close, news times, and Telegram alerts all display in that zone; daily/interval alert timing
  is evaluated in the user's local time.
- **MarketSessionService** — reports each exchange as open / closed / pre-market / after-hours /
  holiday / weekend, with open/close rendered in the viewer's timezone. Holiday calendars live in
  `config/tradenews.php` (`market_holidays`, refreshed yearly).
- **AI summaries (optional)** — with `OPENAI_API_KEY` set, `GenerateNewsSummaryJob` adds a neutral
  2–3 sentence summary per article via OpenAI (rate-limited). Without a key it no-ops and the feed
  shows the article's own summary — nothing breaks.

### Notifications, alerts, provider health & FMP sync

- **In-app notification inbox** — a header bell (unread badge, polled on the user's auto-refresh
  cadence) + a dedicated `/notifications` page with read/unread, categories and filtering. Backed by
  `user_notifications` and `App\Services\Notification\NotificationCenter` (`toUser` / `toAdmins`),
  which can also fan a message to Telegram.
- **Custom alerts** — `/alerts` has a second tab for condition alerts: price above/below,
  daily %-change (absolute), daily gain/drop % over a threshold, volume, news-detected and
  important-news, each with a cooldown, a `trigger_count`, and in-app + optional Telegram delivery.
  `tradenews:evaluate-alerts` (every minute) runs `AlertEvaluator` against the latest cached quote /
  matched news.
- **Provider state machine** — `ProviderHealthService` moves each provider Operational → Degraded →
  Down → (auto-recovers) based on the health probe's success/failure, supports manual Disabled, and
  logs every transition to `provider_events` + notifies admins. Admins get full CRUD over providers
  (markets, capabilities, priority, refresh, auto-recovery) at `/admin/providers`.
- **FMP market data (primary)** — with `FMP_API_KEY`, Financial Modeling Prep supplies the
  **NASDAQ-100 + S&P 500 universe, company profiles, and latest quotes**. `tradenews:sync-quotes`
  batch-fetches quotes for index members only (one request per chunk — never the full NASDAQ),
  caches them in Redis and persists them locally; `tradenews:sync-market-stocks` keeps the universe
  and profiles fresh. Runs are tracked in `sync_runs` and provider health. Without a key it falls
  back to synthetic quotes (and the Finnhub-based universe sync).
- **AI Outlook** — `StockAnalyzer` produces a neutral positive/neutral/negative outlook with
  confidence, opportunities, risks and a disclaimer from backend-only context (quote, multi-day
  change when daily candles exist, volume, news importance, watchlist interest, market session).
  It never emits a price target or buy/sell language.
- **Admin monitoring** — `/admin/provider-events`, `/admin/sync-logs` (last success/failure per
  sync type) and `/admin/system-notifications` (provider/sync/system events sent to admins).

---

## Scheduled commands

Registered in `routes/console.php` (run with `php artisan schedule:work`):

| Command | Cadence | Purpose |
| --- | --- | --- |
| `tradenews:sync-quotes` | every min | FMP batch quotes for NASDAQ-100 + S&P 500 members (when `QUOTE_PROVIDER=fmp`) |
| `tradenews:fetch-prices` | every min | per-stock price fetch (non-FMP quote source, or when historical OHLCV is enabled) |
| `tradenews:fetch-news` | every min | fan out per active provider; fetch + merge news, chain sentiment + matching + AI summary |
| `tradenews:warm-market-summary` | every min | warm the ticker + top-movers cache |
| `tradenews:match-news` | every 10 min | safety-net match sweep for unmatched items |
| `tradenews:dispatch-notifications` | every 5 min | queue Telegram alerts for news rules due this minute (per-user tz) |
| `tradenews:evaluate-alerts` | every min | evaluate custom price/volume/news alerts → in-app + Telegram |
| `tradenews:check-providers` | every 5 min | probe providers → status state machine + events |
| `tradenews:sync-market-stocks` | every min | NASDAQ-100 + S&P 500 universe + profile sync (capability-timed) |
| `tradenews:generate-stock-analyses` | hourly + daily 04:00 | AI Outlook for watchlist/important-news stocks (hourly) and all stocks (daily) |
| `tradenews:cleanup` | daily 03:30 | prune old prices/news/notifications + dedupe |

Each command is checked on its cadence but only does work when due (provider refresh intervals /
freshness windows decide the rest). Run any once manually, e.g. `php artisan tradenews:fetch-news`.

> **Maintenance command:** `tradenews:rebuild-stock-aliases` rebuilds the deterministic
> `stock_aliases` matching index for every stock (run after a deploy that changes alias rules).

Notification cadence: the dispatcher runs every 5 minutes and itself decides which user rules
(5m / 15m / 30m / 1h / 3h / 5h / 1d) are *due* based on the minute-of-day **in each user's
timezone**, then sends only news the user hasn't already been alerted about.

---

## Deployment checklist

After pulling a new release:

1. **Migrate the database** — `php artisan migrate` (never `migrate:fresh` in dev/prod).
2. **Seed/refresh providers** — `php artisan db:seed --class=ApiProviderSeeder` (idempotent).
3. **Rebuild stock aliases** — `php artisan tradenews:rebuild-stock-aliases` so every stock gets
   the latest deterministic alias index.
4. **Seed / sync the universe** — `php artisan tradenews:sync-market-stocks --force` to populate
   NASDAQ-100 + S&P 500 members and profiles (requires `FMP_API_KEY`).
5. **Verify FMP quotes** — `php artisan tradenews:sync-quotes --now` and confirm `/admin/market-data`
   shows the `fmp` provider healthy with quote counts.
6. **Verify RSS news** — `php artisan tradenews:fetch-news` and check `/admin/news-sources`.
7. **Verify the queue worker** — `php artisan queue:work` (jobs are processed, not stuck).
8. **Verify the scheduler** — `php artisan schedule:work` (or a cron calling `schedule:run`).
9. **Verify Telegram** — set the webhook (below) and run the connect flow.
10. **Verify notifications** — trigger an alert and confirm an in-app notification (and Telegram if
    enabled) is created.

### Useful commands

```bash
php artisan tradenews:rebuild-stock-aliases     # rebuild the alias matching index
php artisan tradenews:sync-market-stocks --force # sync NASDAQ-100 + S&P 500 universe + profiles
php artisan tradenews:sync-quotes --now          # batch-sync FMP quotes for index members
php artisan tradenews:check-providers            # run provider health probes now
php artisan queue:work                           # process queued jobs
php artisan schedule:work                        # run the scheduler
```

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
