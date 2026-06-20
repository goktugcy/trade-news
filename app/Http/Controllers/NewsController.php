<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Enums\Sentiment;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Services\Translation\ContentTranslationService;
use App\Support\Presenters\NewsPresenter;
use Illuminate\Database\Eloquent\Builder;
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
                in_array($market, [Market::BIST->value, Market::NASDAQ->value], true),
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
            });
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

        app(ContentTranslationService::class)->queueNewsTranslations($items, $locale);

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

        if (in_array($requested, [Market::BIST->value, Market::NASDAQ->value], true)) {
            return $requested;
        }

        $preferredMarkets = $request->user()->dataPreference?->preferred_markets ?? [];

        return count($preferredMarkets) === 1 ? (string) $preferredMarkets[0] : '';
    }
}
