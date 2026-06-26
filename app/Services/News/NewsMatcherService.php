<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\Market;
use App\Models\NewsItem;
use Illuminate\Support\Collection;

/**
 * Tags a news item with the stocks it mentions. Matching itself is fully
 * deterministic and delegated to StockAliasService (symbol / name / alias with
 * confidence); this class persists news_stock_matches and resolves the item's
 * market from the matched stocks.
 */
class NewsMatcherService
{
    private readonly StockAliasService $aliases;

    public function __construct(?StockAliasService $aliases = null)
    {
        $this->aliases = $aliases ?? app(StockAliasService::class);
    }

    /**
     * Match a single news item, persisting news_stock_matches rows.
     *
     * @return int number of stocks matched
     */
    public function match(NewsItem $item): int
    {
        $text = trim(implode(' ', array_filter([
            $item->title,
            $item->summary,
            $item->content,
        ])));

        if ($text === '') {
            $item->forceFill(['is_matched' => true])->save();

            return 0;
        }

        $matches = $this->aliases->relatedStocks($text);

        foreach ($matches as $stockId => $data) {
            $item->matches()->updateOrCreate(
                ['stock_id' => $stockId],
                [
                    'match_type' => $data['match_type'],
                    'matched_term' => $data['matched_term'],
                    'confidence' => $data['confidence'],
                    'created_at' => now(),
                ],
            );
        }

        $updates = ['is_matched' => true];
        $market = $this->marketFromMatches($matches);

        if ($market !== null) {
            $updates['market'] = $market;
        }

        $item->forceFill($updates)->save();

        return count($matches);
    }

    /**
     * @param  array<int, array{match_type: string, matched_term: string, confidence: float, market: string}>  $matches
     */
    private function marketFromMatches(array $matches): ?Market
    {
        $markets = collect($matches)
            ->pluck('market')
            ->filter()
            ->unique()
            ->values();

        if ($markets->count() !== 1) {
            return null;
        }

        return Market::tryFrom((string) $markets->first());
    }

    /**
     * Reset the memoized alias index (tests call this after seeding stocks).
     */
    public function flushIndex(): void
    {
        $this->aliases->flushIndex();
    }

    /**
     * Convenience: match a batch of unmatched items.
     *
     * @param  Collection<int, NewsItem>  $items
     */
    public function matchMany(Collection $items): int
    {
        return $items->sum(fn (NewsItem $item) => $this->match($item));
    }
}
