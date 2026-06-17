<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Enums\Sentiment;
use App\Models\NewsItem;
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
            'news' => $this->paginate($this->baseQuery($request)),
            'filters' => $this->filters($request),
            'options' => $this->filterOptions(),
            'scope' => 'all',
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
            'news' => $this->paginate($query),
            'filters' => $this->filters($request),
            'options' => $this->filterOptions(),
            'scope' => 'watchlist',
            'watchlistEmpty' => $stockIds->isEmpty(),
        ]);
    }

    /**
     * @return Builder<NewsItem>
     */
    private function baseQuery(Request $request): Builder
    {
        $market = $request->string('market')->upper()->toString();
        $sentiment = $request->string('sentiment')->lower()->toString();
        $search = trim($request->string('q')->toString());

        return NewsItem::query()
            ->where('is_matched', true)
            ->published()
            ->with(['stocks:id,symbol,market', 'source:id,name'])
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
    private function paginate(Builder $query): array
    {
        $paginator = $query->paginate(15)->withQueryString();

        return [
            'data' => NewsPresenter::collection($paginator->items()),
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
            'market' => $request->string('market')->upper()->toString() ?: 'ALL',
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
}
