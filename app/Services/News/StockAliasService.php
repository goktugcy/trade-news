<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\Stock;
use App\Models\StockAlias;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic, alias-based stock tagging — no LLMs, NER or embeddings.
 *
 * Owns alias normalization, the rebuildable `stock_aliases` matching index
 * (derived from each stock's symbol, name, suffix-stripped name, the editable
 * `aliases` JSON, and a small curated map), and the text → related-stock
 * matching with confidence scoring.
 */
class StockAliasService
{
    private const int SHORT_ALIAS_MAX_LENGTH = 3;

    /** Legal/structural suffixes stripped to derive a looser company-name alias. */
    private const array NAME_SUFFIXES = [
        'inc', 'incorporated', 'corp', 'corporation', 'co', 'company', 'plc', 'ltd',
        'limited', 'llc', 'lp', 'holdings', 'holding', 'group', 'sa', 'nv', 'ag', 'se',
        'class a', 'class b', 'class c', 'the',
    ];

    /**
     * Curated extra aliases keyed by ticker — well-known names the deterministic
     * derivation can't infer (former names, brands, sibling share classes).
     *
     * @var array<string, array<int, string>>
     */
    private const array CURATED = [
        'META' => ['Facebook'],
        'GOOGL' => ['Google', 'Alphabet', 'GOOG'],
        'GOOG' => ['Google', 'Alphabet', 'GOOGL'],
        'NVDA' => ['Nvidia'],
        'TSLA' => ['Tesla Motors'],
        'BRK-B' => ['Berkshire', 'Berkshire Hathaway'],
        'AMD' => ['Advanced Micro Devices'],
    ];

    /**
     * Cached flat index: [stockId, kind, alias, normalized, confidence, market].
     *
     * @var array<int, array{0: int, 1: string, 2: string, 3: string, 4: float, 5: string}>|null
     */
    private ?array $index = null;

    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^\pL\pN]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Derive the full alias set for a stock (deduped by normalized form, keeping
     * the highest-confidence kind). Order matters: higher-confidence kinds first.
     *
     * @return array<int, array{alias: string, normalized: string, kind: string, confidence: float}>
     */
    public function aliasesFor(Stock $stock): array
    {
        $entries = [];

        $add = function (string $alias, string $kind, float $confidence) use (&$entries): void {
            $normalized = $this->normalize($alias);

            // First write wins — callers add in descending confidence order.
            if ($normalized === '' || isset($entries[$normalized])) {
                return;
            }

            $entries[$normalized] = [
                'alias' => trim($alias),
                'normalized' => $normalized,
                'kind' => $kind,
                'confidence' => $confidence,
            ];
        };

        $add($stock->symbol, StockAlias::KIND_TICKER, StockAlias::CONFIDENCE[StockAlias::KIND_TICKER]);
        $add($stock->name, StockAlias::KIND_NAME, StockAlias::CONFIDENCE[StockAlias::KIND_NAME]);

        foreach (($stock->aliases ?? []) as $alias) {
            $add((string) $alias, StockAlias::KIND_ALIAS, StockAlias::CONFIDENCE[StockAlias::KIND_ALIAS]);
        }

        foreach (self::CURATED[mb_strtoupper($stock->symbol)] ?? [] as $alias) {
            $add($alias, StockAlias::KIND_ALIAS, StockAlias::CONFIDENCE[StockAlias::KIND_ALIAS]);
        }

        // Looser, suffix-stripped company name ("Apple Inc." → "Apple") scores lower.
        $stripped = $this->stripSuffix($stock->name);

        if ($stripped !== '') {
            $add($stripped, StockAlias::KIND_ALIAS, StockAlias::CONFIDENCE_DERIVED);
        }

        return array_values($entries);
    }

    /**
     * Rebuild the stock_aliases index rows for one stock (delete + insert of its
     * own derived rows — a maintenance op on a derived index, not user data).
     */
    public function rebuildFor(Stock $stock): void
    {
        $now = now();
        $rows = array_map(fn (array $e): array => [
            'stock_id' => $stock->id,
            'alias' => $e['alias'],
            'normalized' => $e['normalized'],
            'kind' => $e['kind'],
            'confidence' => $e['confidence'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->aliasesFor($stock));

        DB::transaction(function () use ($stock, $rows): void {
            StockAlias::query()->where('stock_id', $stock->id)->delete();

            if ($rows !== []) {
                StockAlias::query()->insert($rows);
            }
        });

        $this->index = null;
    }

    /**
     * Rebuild the index for every stock. Returns the number of stocks processed.
     */
    public function rebuildAll(): int
    {
        $count = 0;

        Stock::query()->orderBy('id')->chunkById(200, function ($stocks) use (&$count): void {
            foreach ($stocks as $stock) {
                $this->rebuildFor($stock);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Match free text to the stocks it mentions. Returns the best (highest
     * confidence) reason per stock, keyed by stock id.
     *
     * @return array<int, array{match_type: string, matched_term: string, confidence: float, market: string}>
     */
    public function relatedStocks(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $haystack = mb_strtolower($text);
        $matches = [];

        foreach ($this->termIndex() as [$stockId, $kind, $alias, $normalized, $confidence, $market]) {
            if (! $this->contains($text, $haystack, $alias, $normalized, $kind)) {
                continue;
            }

            if (! isset($matches[$stockId]) || $matches[$stockId]['confidence'] < $confidence) {
                $matches[$stockId] = [
                    // Persisted match_type stays "symbol" for tickers (back-compat).
                    'match_type' => $kind === StockAlias::KIND_TICKER ? 'symbol' : $kind,
                    'matched_term' => $alias,
                    'confidence' => $confidence,
                    'market' => $market,
                ];
            }
        }

        return $matches;
    }

    public function flushIndex(): void
    {
        $this->index = null;
    }

    /**
     * Build (and memoize) the flat alias index for active stocks from the table.
     *
     * @return array<int, array{0: int, 1: string, 2: string, 3: string, 4: float, 5: string}>
     */
    private function termIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];

        StockAlias::query()
            ->join('stocks', 'stocks.id', '=', 'stock_aliases.stock_id')
            ->where('stocks.is_active', true)
            ->orderBy('stock_aliases.id')
            ->get([
                'stock_aliases.stock_id',
                'stock_aliases.kind',
                'stock_aliases.alias',
                'stock_aliases.normalized',
                'stock_aliases.confidence',
                'stocks.market',
            ])
            ->each(function ($row): void {
                $this->index[] = [
                    (int) $row->stock_id,
                    (string) $row->kind,
                    (string) $row->alias,
                    (string) $row->normalized,
                    (float) $row->confidence,
                    (string) $row->market,
                ];
            });

        return $this->index;
    }

    /**
     * Whole-phrase containment. Tickers match case-sensitively with word
     * boundaries (and ignore possessives/contractions); short aliases stay
     * case-sensitive to avoid generic words ("THY"); the rest are case-insensitive.
     */
    private function contains(string $text, string $haystack, string $alias, string $normalized, string $kind): bool
    {
        if ($normalized === '') {
            return false;
        }

        if ($kind === StockAlias::KIND_TICKER) {
            return $this->containsSymbol($text, $alias);
        }

        if ($kind !== StockAlias::KIND_NAME && mb_strlen($normalized) <= self::SHORT_ALIAS_MAX_LENGTH) {
            return $this->containsPhrase($text, $alias, ignoreCase: false);
        }

        return $this->containsPhrase($haystack, $normalized, ignoreCase: true);
    }

    private function containsSymbol(string $text, string $symbol): bool
    {
        $pattern = '/(?<![\pL\pN])'.preg_quote($symbol, '/')."(?!['’][sS])(?![\pL\pN])/u";

        return preg_match($pattern, $text) === 1;
    }

    private function containsPhrase(string $haystack, string $needle, bool $ignoreCase): bool
    {
        $flags = $ignoreCase ? 'iu' : 'u';
        $pattern = '/(?<![\pL\pN])'.preg_quote($needle, '/').'(?![\pL\pN])/'.$flags;

        return preg_match($pattern, $haystack) === 1;
    }

    private function stripSuffix(string $name): string
    {
        $normalized = $this->normalize($name);

        if ($normalized === '') {
            return '';
        }

        $changed = true;

        while ($changed) {
            $changed = false;

            foreach (self::NAME_SUFFIXES as $suffix) {
                if (str_ends_with($normalized, ' '.$suffix)) {
                    $normalized = trim(mb_substr($normalized, 0, -mb_strlen($suffix) - 1));
                    $changed = true;
                }
            }
        }

        // Only useful if it actually shortened a multi-word name.
        return $normalized !== '' && $normalized !== $this->normalize($name) ? $normalized : '';
    }
}
