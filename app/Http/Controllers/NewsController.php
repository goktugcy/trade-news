<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Enums\Sentiment;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Support\Presenters\NewsPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewsController extends Controller
{
    /**
     * The "All News" feed with market / sentiment / search filters.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('news/Index', [
            'news' => $this->paginate($this->baseQuery($request), $request),
            'filters' => $this->filters($request),
            'options' => $this->filterOptions(),
            'scope' => 'all',
            'sources' => $this->sourceOptions($request),
        ]);
    }

    /**
     * The "Watchlist News" feed — only news matched to the user's watched stocks.
     */
    public function watchlist(Request $request): Response
    {
        $stockIds = $request->user()->watchlist()->pluck('stock_id');

        $query = $this->baseQuery($request)
            ->whereHas('stocks', fn (Builder $q) => $q->whereIn('stocks.id', $stockIds));

        return Inertia::render('news/Index', [
            'news' => $this->paginate($query, $request),
            'filters' => $this->filters($request),
            'options' => $this->filterOptions(),
            'scope' => 'watchlist',
            'watchlistEmpty' => $stockIds->isEmpty(),
            'sources' => $this->sourceOptions($request),
        ]);
    }

    /**
     * The "Saved News" feed — only news the user has bookmarked.
     */
    public function saved(Request $request): Response
    {
        $uid = $request->user()->id;

        $query = $this->baseQuery($request)
            ->whereHas('savedForUser', fn (Builder $q) => $q->where('user_id', $uid));

        return Inertia::render('news/Saved', [
            'news' => $this->paginate($query, $request),
            'filters' => $this->filters($request),
            'options' => $this->filterOptions(),
            'scope' => 'saved',
        ]);
    }

    /**
     * Polling endpoint: returns brand-new items (id > after) for the Twitter-style
     * "+N new" pill, plus refreshed cards for already-visible ids (so translation /
     * sentiment / counts swap in place without a page reload).
     */
    public function live(Request $request): JsonResponse
    {
        $scope = $request->string('scope')->toString();
        $scope = in_array($scope, ['all', 'watchlist', 'saved'], true) ? $scope : 'all';
        $locale = $request->user()->locale;

        $ids = collect(explode(',', $request->string('ids')->toString()))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter(fn (int $id): bool => $id > 0)
            ->take(60)
            ->values();

        // Keyset cursor anchored on the top item's id: read its exact timestamp
        // from the DB (microsecond precision) and return only rows strictly above
        // it in the feed order (published_at, id). This avoids the serialization
        // pitfall where a seconds-truncated cursor makes same-second rows look
        // "new" on every refresh. Already-shown rows are never re-offered.
        $anchor = ($afterId = $request->integer('after_id')) > 0
            ? NewsItem::query()->find($afterId)
            : null;

        $stock = $request->string('stock')->upper()->toString();
        $stockFilter = fn (Builder $q): Builder => $q->when(
            $stock !== '',
            fn (Builder $inner) => $inner->whereHas('stocks', fn (Builder $s) => $s->where('symbol', $stock)),
        );

        $newItems = collect();

        if ($anchor?->published_at !== null) {
            $anchorTs = $anchor->published_at->format('Y-m-d H:i:s.u');

            $newItems = $stockFilter($this->scopedQuery($request, $scope))
                ->where(fn (Builder $q) => $q
                    ->where('published_at', '>', $anchorTs)
                    ->orWhere(fn (Builder $tie) => $tie
                        ->where('published_at', $anchorTs)
                        ->where('id', '>', $anchor->id)))
                ->limit(20)
                ->get();
        }

        $updates = $ids->isNotEmpty()
            ? $stockFilter($this->scopedQuery($request, $scope))->whereIn('id', $ids)->get()
            : collect();

        return response()->json([
            'items' => NewsPresenter::collection($newItems, $locale),
            'updates' => NewsPresenter::collection($updates, $locale),
        ]);
    }

    /**
     * Base feed query narrowed to a scope (all | watchlist | saved).
     *
     * @return Builder<NewsItem>
     */
    private function scopedQuery(Request $request, string $scope): Builder
    {
        $query = $this->baseQuery($request);
        $uid = $request->user()->id;

        return match ($scope) {
            'watchlist' => $query->whereHas(
                'stocks',
                fn (Builder $q) => $q->whereIn('stocks.id', $request->user()->watchlist()->pluck('stock_id')),
            ),
            'saved' => $query->whereHas('savedForUser', fn (Builder $q) => $q->where('user_id', $uid)),
            default => $query,
        };
    }

    /**
     * @return Builder<NewsItem>
     */
    private function baseQuery(Request $request): Builder
    {
        $market = $this->selectedMarket($request);
        $sentiment = $request->string('sentiment')->lower()->toString();
        $search = trim($request->string('q')->toString());
        $uid = $request->user()->id;
        $disabled = $request->user()->disabledNewsSources()->pluck('news_source_id');
        $locale = $request->user()->locale;

        return NewsItem::query()
            ->where('is_matched', true)
            ->fromActiveSource()
            ->published()
            ->with([
                'stocks:id,symbol,market', 'source:id,name,language', 'sources.source:id,name',
                'translations' => fn ($q) => $q->where('locale', $locale),
                'reactionForUser' => fn ($q) => $q->where('user_id', $uid),
                'savedForUser' => fn ($q) => $q->where('user_id', $uid),
            ])
            ->withCount(['likes', 'dislikes'])
            ->when(
                $disabled->isNotEmpty(),
                fn (Builder $q) => $q->whereNotIn('source_id', $disabled),
            )
            ->when(
                $market === Market::NASDAQ->value,
                fn (Builder $q) => $q->where('market', $market),
            )
            ->when(
                in_array($sentiment, array_column(Sentiment::cases(), 'value'), true),
                fn (Builder $q) => $q->where('sentiment', $sentiment),
            )
            ->when($search !== '', function (Builder $q) use ($search): void {
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('title', 'ILIKE', "%{$search}%")
                        ->orWhere('summary', 'ILIKE', "%{$search}%")
                        ->orWhereHas('stocks', fn (Builder $s) => $s->where('symbol', 'ILIKE', "%{$search}%"));
                });
            })
        // Newest-published first. The live feed's cursor is published_at
        // based (see live()), so the SSR feed and the poll agree: items
        // already shown are never re-offered after a refresh, and genuinely
        // newer articles surface at the top.
            ->reorder('published_at', 'desc')
            ->orderByDesc('id');
    }

    /**
     * @param  Builder<NewsItem>  $query
     * @return array<string, mixed>
     */
    private function paginate(Builder $query, Request $request): array
    {
        $paginator = $query->paginate(15)->withQueryString();
        $items = collect($paginator->items());
        $locale = $request->user()->locale;

        return [
            'data' => NewsPresenter::collection($items, $locale),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        return [
            'market' => $this->selectedMarket($request) ?: 'ALL',
            'sentiment' => $request->string('sentiment')->lower()->toString() ?: null,
            'q' => trim($request->string('q')->toString()) ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'markets' => Market::options(),
            'sentiments' => array_map(fn (Sentiment $s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
            ], Sentiment::cases()),
        ];
    }

    /**
     * Active sources with the user's per-source enabled state (default opt-out).
     *
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
