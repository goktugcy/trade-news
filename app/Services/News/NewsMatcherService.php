<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\Market;
use App\Models\NewsItem;
use App\Models\Stock;
use Illuminate\Support\Collection;

/**
 * Matches a news item to the stocks it mentions, using symbol, company name,
 * aliases and keywords. For example:
 *
 *   THYAO  ⇐ "THYAO", "Türk Hava Yolları", "Turkish Airlines", "THY"
 *   ASELS  ⇐ "ASELS", "Aselsan", "Aselsan Elektronik"
 */
class NewsMatcherService
{
    private const TYPE_SYMBOL = 'symbol';

    private const TYPE_NAME = 'name';

    private const TYPE_ALIAS = 'alias';

    private const TYPE_KEYWORD = 'keyword';

    private const CONFIDENCE = [
        self::TYPE_SYMBOL => 1.0,
        self::TYPE_NAME => 0.9,
        self::TYPE_ALIAS => 0.85,
        self::TYPE_KEYWORD => 0.6,
    ];

    /**
     * Cached term index: list of [stockId, type, term] tuples.
     *
     * @var array<int, array{0: int, 1: string, 2: string, 3: string}>|null
     */
    private ?array $index = null;

    /**
     * Match a single news item, persisting news_stock_matches rows.
     *
     * @return int number of stocks matched
     */
    public function match(NewsItem $item): int
    {
        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            $item->title,
            $item->summary,
            $item->content,
        ]))));

        if ($haystack === '') {
            $item->forceFill(['is_matched' => true])->save();

            return 0;
        }

        // Best match per stock (keep the highest-confidence reason).
        $matches = [];

        foreach ($this->termIndex() as [$stockId, $type, $term, $market]) {
            $needle = mb_strtolower($term);

            if (! $this->contains($haystack, $needle, $type)) {
                continue;
            }

            $confidence = self::CONFIDENCE[$type];

            if (! isset($matches[$stockId]) || $matches[$stockId]['confidence'] < $confidence) {
                $matches[$stockId] = [
                    'match_type' => $type,
                    'matched_term' => $term,
                    'confidence' => $confidence,
                    'market' => $market,
                ];
            }
        }

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
     * Symbols are matched on word boundaries; names/aliases/keywords on substring.
     */
    private function contains(string $haystack, string $needle, string $type): bool
    {
        if ($needle === '') {
            return false;
        }

        if ($type === self::TYPE_SYMBOL) {
            return (bool) preg_match('/\b'.preg_quote($needle, '/').'\b/u', $haystack);
        }

        return mb_strpos($haystack, $needle) !== false;
    }

    /**
     * Build (and memoize) the flat term → stock index from all active stocks.
     *
     * @return array<int, array{0: int, 1: string, 2: string, 3: string}>
     */
    private function termIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];

        Stock::query()->active()->get()->each(function (Stock $stock): void {
            $market = $stock->market->value;
            $this->index[] = [$stock->id, self::TYPE_SYMBOL, $stock->symbol, $market];
            $this->index[] = [$stock->id, self::TYPE_NAME, $stock->name, $market];

            foreach (($stock->aliases ?? []) as $alias) {
                if (trim($alias) !== '' && $alias !== $stock->symbol && $alias !== $stock->name) {
                    $this->index[] = [$stock->id, self::TYPE_ALIAS, $alias, $market];
                }
            }

            foreach (($stock->keywords ?? []) as $keyword) {
                if (trim($keyword) !== '') {
                    $this->index[] = [$stock->id, self::TYPE_KEYWORD, $keyword, $market];
                }
            }
        });

        return $this->index;
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
     * Allow callers (tests) to reset the memoized index after seeding stocks.
     */
    public function flushIndex(): void
    {
        $this->index = null;
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
