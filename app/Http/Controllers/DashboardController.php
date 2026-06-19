<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NewsItem;
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

        $feed = NewsItem::query()
            ->where('is_matched', true)
            ->fromActiveSource()
            ->published()
            ->with([
                'stocks:id,symbol,market', 'source:id,name', 'sources.source:id,name',
                'reactionForUser' => fn ($q) => $q->where('user_id', $user->id),
                'savedForUser' => fn ($q) => $q->where('user_id', $user->id),
            ])
            ->withCount(['likes', 'dislikes'])
            ->when(
                $disabledSourceIds->isNotEmpty(),
                fn ($q) => $q->whereNotIn('source_id', $disabledSourceIds),
            )
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
            'feed' => NewsPresenter::collection($feed),
            'watchlist' => $watchlist,
            'topMovers' => $summary->topMovers(),
            'marketStatus' => $sessions->all($user->timezone),
            'latestAlerts' => $latestAlerts,
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
}
