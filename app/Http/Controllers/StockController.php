<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiTask;
use App\Enums\AlertType;
use App\Enums\Market;
use App\Models\Stock;
use App\Models\StockAiAnalysis;
use App\Models\StockAlert;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiTaskService;
use App\Services\Market\MarketSessionService;
use App\Services\MarketData\MarketSummaryService;
use App\Services\Translation\ContentTranslationService;
use App\Support\Presenters\NewsPresenter;
use App\Support\Presenters\StockPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    /**
     * Searchable stock list grouped by market.
     */
    public function index(Request $request): Response
    {
        $market = $this->selectedMarket($request);
        $search = trim($request->string('q')->toString());
        $watchlistIds = $request->user()->watchlist()->pluck('stock_id')->all();

        $stocks = Stock::query()
            ->active()
            ->with('latestPrice')
            ->when(
                $market === Market::NASDAQ->value,
                fn (Builder $q) => $q->where('market', $market),
            )
            ->when($search !== '', fn (Builder $q) => $q->search($search))
            ->orderBy('symbol')
            ->get()
            ->map(fn (Stock $stock) => StockPresenter::row($stock, [
                'in_watchlist' => in_array($stock->id, $watchlistIds, true),
            ]));

        return Inertia::render('stocks/Index', [
            'stocks' => $stocks,
            'filters' => [
                'market' => $market ?: 'ALL',
                'q' => $search ?: null,
            ],
            'options' => ['markets' => Market::options()],
        ]);
    }

    /**
     * Stock detail page (route-model-bound by symbol).
     */
    public function show(Request $request, Stock $stock, MarketSessionService $sessions): Response
    {
        $user = $request->user();

        $watchlistEntry = $user->watchlist()
            ->where('stock_id', $stock->id)
            ->first();

        $uid = $user->id;
        $disabledSourceIds = $user->disabledNewsSources()->pluck('news_source_id');
        $locale = $user->locale;

        $relatedNews = $stock->news()
            ->where('is_matched', true)
            ->whereHas('source', fn (Builder $q) => $q->where('is_active', true))
            ->with([
                'stocks:id,symbol,market', 'source:id,name,language', 'sources.source:id,name',
                'translations' => fn ($q) => $q->where('locale', $locale),
                'reactionForUser' => fn ($q) => $q->where('user_id', $uid),
                'savedForUser' => fn ($q) => $q->where('user_id', $uid),
            ])
            ->withCount(['likes', 'dislikes'])
            ->when(
                $disabledSourceIds->isNotEmpty(),
                fn (Builder $q) => $q->whereNotIn('source_id', $disabledSourceIds),
            )
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();

        $profile = is_array($stock->company_profile) ? $stock->company_profile : [];

        return Inertia::render('stocks/Show', [
            'stock' => [
                ...StockPresenter::row($stock->load('latestPrice'), [
                    'in_watchlist' => $watchlistEntry !== null,
                    'alerts_enabled' => $watchlistEntry !== null && $watchlistEntry->alerts_enabled,
                    'watchlist_id' => $watchlistEntry?->id,
                ]),
                'logo_url' => $stock->logo_url,
                'description' => $stock->description,
                'ceo' => is_string($profile['ceo'] ?? null) ? $profile['ceo'] : null,
                'index_keys' => $stock->currentIndexKeys(),
            ],
            'news' => NewsPresenter::collection($relatedNews, $locale),
            'analysis' => $this->analysisPayload($stock, $locale),
            'marketStatus' => $sessions->session($stock->market, $user->timezone),
            'stockAlerts' => $this->stockAlertsFor($user, $stock),
            'alertTypes' => AlertType::options(),
            'telegramConnected' => (bool) $user->telegramIntegration?->isActive(),
            'recentActivity' => $this->recentActivityFor($user, $stock),
        ]);
    }

    /**
     * The user's alerts for this stock (mirrors the alerts page contract).
     *
     * @return array<int, array<string, mixed>>
     */
    private function stockAlertsFor(User $user, Stock $stock): array
    {
        return $user->stockAlerts()
            ->where('stock_id', $stock->id)
            ->latest('id')
            ->get()
            ->map(fn (StockAlert $a): array => [
                'id' => $a->id,
                'stock_id' => $a->stock_id,
                'type' => $a->type->value,
                'type_label' => $a->type->label(),
                'threshold' => $a->threshold,
                'cooldown_minutes' => $a->cooldown_minutes,
                'is_active' => $a->is_active,
                'notify_in_app' => $a->notify_in_app,
                'notify_telegram' => $a->notify_telegram,
                'last_triggered_at' => $a->last_triggered_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Recent in-app notifications tied to this stock (alert/news triggers).
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentActivityFor(User $user, Stock $stock): array
    {
        return $user->userNotifications()
            ->where('data->stock_id', $stock->id)
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (UserNotification $n): array => [
                'id' => $n->id,
                'category' => $n->category->value,
                'title' => $n->title,
                'body' => $n->body,
                'created_at' => $n->created_at?->toIso8601String(),
                'read_at' => $n->read_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The latest cached AI analysis for the stock detail page. Read-only — never
     * triggers AI generation (that happens in GenerateStockAnalysisJob).
     *
     * @return array<string, mixed>|null
     */
    private function analysisPayload(Stock $stock, string $locale): ?array
    {
        $analysis = $stock->latestAiAnalysis()
            ->with(['translations' => fn ($query) => $query->where('locale', $locale)])
            ->first();

        $aiEnabled = app(AiTaskService::class)->isEnabled(AiTask::StockAnalysis);

        if ($analysis === null) {
            return null;
        }

        $translation = $analysis->translationFor($locale);

        return [
            'signal' => $analysis->signal->value,
            'signal_label' => $analysis->signal->label(),
            'signal_color' => $analysis->signal->color(),
            'confidence' => $analysis->confidence,
            'horizon' => $analysis->horizon,
            'estimated_price_low' => $analysis->estimated_price_low,
            'estimated_price_high' => $analysis->estimated_price_high,
            'estimated_price' => $analysis->estimated_price,
            'currency' => $analysis->currency,
            'summary' => $translation?->summary ?: $analysis->summary,
            'drivers' => $translation?->drivers ?: ($analysis->drivers ?? []),
            'risks' => $translation?->risks ?: ($analysis->risks ?? []),
            'disclaimer' => $translation?->disclaimer ?: ($analysis->disclaimer ?? StockAiAnalysis::DISCLAIMER),
            'translation_locale' => $translation?->locale,
            'translation_status' => $translation !== null
                ? ContentTranslationService::STATUS_TRANSLATED
                : ContentTranslationService::STATUS_ORIGINAL,
            'can_translate' => app(ContentTranslationService::class)->canTranslateAnalysis($analysis, $locale),
            'generated_at' => $analysis->generated_at?->diffForHumans(),
            'is_stale' => $analysis->isStale() || ! $aiEnabled,
            'ai_enabled' => $aiEnabled,
        ];
    }

    /**
     * On-demand: translate the stock's latest AI analysis to the user's locale
     * synchronously and return the refreshed analysis payload (no page reload).
     */
    public function translateAnalysis(Request $request, Stock $stock): JsonResponse
    {
        $locale = $request->user()->locale;
        $analysis = $stock->latestAiAnalysis()->first();

        if ($analysis === null) {
            return response()->json(['ok' => false], 404);
        }

        app(ContentTranslationService::class)->translateStockAnalysis($analysis, $locale);

        $payload = $this->analysisPayload($stock, $locale);

        return response()->json([
            'ok' => $payload !== null && ($payload['translation_locale'] ?? null) !== null,
            'analysis' => $payload,
        ]);
    }

    /**
     * Polling endpoint: live quotes for the given symbols (cached, cheap) plus
     * the shared ticker / top-movers / market status so the dashboard and stock
     * pages update without a reload.
     */
    public function liveQuotes(Request $request, MarketSummaryService $summary, MarketSessionService $sessions): JsonResponse
    {
        $symbols = collect(explode(',', $request->string('symbols')->upper()->toString()))
            ->map(fn (string $symbol): string => trim($symbol))
            ->filter(fn (string $symbol): bool => $symbol !== '')
            ->unique()
            ->take(60)
            ->values();

        $quotes = $symbols->isEmpty()
            ? []
            : Stock::query()
                ->active()
                ->whereIn('symbol', $symbols)
                ->with('latestPrice')
                ->get()
                ->map(function (Stock $stock): array {
                    $row = StockPresenter::row($stock);

                    return [
                        'symbol' => $row['symbol'],
                        'price' => $row['price'],
                        'change' => $row['change'],
                        'change_percent' => $row['change_percent'],
                        'quote_at' => $row['quote_at'],
                    ];
                })
                ->all();

        return response()->json([
            'quotes' => $quotes,
            'ticker' => $summary->cachedTicker(),
            'top_movers' => $summary->cachedTopMovers(),
            'market_status' => $sessions->all($request->user()->timezone),
        ]);
    }

    /**
     * Typeahead search used by the watchlist add box (JSON).
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        if (mb_strlen($term) < 1) {
            return response()->json(['results' => []]);
        }

        $results = Stock::query()
            ->active()
            ->search($term)
            ->orderBy('symbol')
            ->limit(10)
            ->get(['id', 'symbol', 'name', 'market'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'symbol' => $s->symbol,
                'name' => $s->name,
                'market' => $s->market->value,
            ]);

        return response()->json(['results' => $results]);
    }

    private function selectedMarket(Request $request): string
    {
        $requested = $request->string('market')->upper()->toString();

        if ($requested === 'ALL') {
            return '';
        }

        if ($requested === Market::NASDAQ->value) {
            return $requested;
        }

        $preferredMarkets = $request->user()->dataPreference?->preferred_markets ?? [];

        return count($preferredMarkets) === 1 && $preferredMarkets[0] === Market::NASDAQ->value
            ? Market::NASDAQ->value
            : '';
    }
}
