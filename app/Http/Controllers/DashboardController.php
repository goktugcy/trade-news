<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\Watchlist;
use App\Services\Market\MarketSessionService;
use App\Services\MarketData\MarketSummaryService;
use App\Support\Presenters\NewsPresenter;
use App\Support\Presenters\StockPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MarketSummaryService $summary, MarketSessionService $sessions): Response
    {
        $user = $request->user();

        $watchlistStockIds = $user->watchlist()->pluck('stock_id');
        $disabledSourceIds = $user->disabledNewsSources()->pluck('news_source_id');
        $preferredMarkets = $user->dataPreference?->preferred_markets ?? [];
        $locale = $user->locale;

        $feed = NewsItem::query()
            ->where('is_matched', true)
            ->fromActiveSource()
            ->published()
            ->with([
                'stocks:id,symbol,market', 'source:id,name,language', 'sources.source:id,name',
                'translations' => fn ($q) => $q->where('locale', $locale),
                'reactionForUser' => fn ($q) => $q->where('user_id', $user->id),
                'savedForUser' => fn ($q) => $q->where('user_id', $user->id),
            ])
            ->withCount(['likes', 'dislikes'])
            ->when(
                $disabledSourceIds->isNotEmpty(),
                fn ($q) => $q->whereNotIn('source_id', $disabledSourceIds),
            )
            ->when(
                $preferredMarkets !== [],
                fn ($q) => $q->whereIn('market', $preferredMarkets),
            )
            // Order by id (ingestion order), matching the live poll's cursor so
            // already-seen items aren't re-offered as "new" after a refresh.
            ->reorder('id', 'desc')
            ->limit(12)
            ->get();

        $watchlist = $user->watchlist()
            ->with('stock.latestPrice')
            ->get()
            ->map(fn (Watchlist $item) => StockPresenter::row($item->stock, [
                'in_watchlist' => true,
                'alerts_enabled' => $item->alerts_enabled,
            ]));

        $latestAlerts = $user->alertLogs()
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'status' => $n->status,
                'channel' => $n->channel,
                'sent_at' => $n->sent_at?->diffForHumans(),
                'created_at' => $n->created_at?->diffForHumans(),
            ]);

        return Inertia::render('Dashboard', [
            'feed' => NewsPresenter::collection($feed, $locale),
            'watchlist' => $watchlist,
            'topMovers' => $summary->cachedTopMovers(),
            'marketStatus' => $sessions->all($user->timezone),
            'latestAlerts' => $latestAlerts,
            'onboardingOptions' => [
                'sources' => $this->sourceOptions($request),
                'markets' => Market::options(),
            ],
            'stats' => [
                'watchlist_count' => $watchlistStockIds->count(),
                'matched_news_today' => NewsItem::query()
                    ->where('is_matched', true)
                    ->fromActiveSource()
                    ->where('published_at', '>=', now()->startOfDay())
                    ->count(),
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceOptions(Request $request): array
    {
        $disabled = $request->user()->disabledNewsSources()->pluck('news_source_id');

        return NewsSource::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'language'])
            ->map(fn (NewsSource $source) => [
                'id' => $source->id,
                'name' => $source->name,
                'language' => $source->language,
                'enabled' => ! $disabled->contains($source->id),
            ])
            ->all();
    }
}
