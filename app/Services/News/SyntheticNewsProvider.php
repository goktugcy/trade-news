<?php

declare(strict_types=1);

namespace App\Services\News;

use App\DataTransferObjects\NewsItemData;
use App\Enums\Market;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Generates believable market headlines referencing real seeded stocks so the
 * feed, matcher, sentiment and notification pipeline can be exercised without
 * any external news API or key.
 */
class SyntheticNewsProvider implements NewsProviderInterface
{
    /** @var array<int, string> */
    private array $templates = [
        '{name} reports record quarterly revenue, beating analyst estimates',
        '{name} announces new buyback program worth billions',
        '{name} shares slide after weaker-than-expected guidance',
        'Analysts upgrade {symbol} on strong demand outlook',
        '{name} signs strategic partnership to expand operations',
        'Regulators open inquiry into {name} accounting practices',
        '{name} unveils next-generation product line at investor day',
        '{symbol} dividend raised as cash flow strengthens',
        '{name} faces supply chain headwinds amid rising costs',
        'Insiders buy {symbol} stock ahead of earnings call',
    ];

    public function key(): string
    {
        return 'synthetic';
    }

    public function fetchLatest(?Market $market = null, int $limit = 50): array
    {
        $stocks = Stock::query()
            ->active()
            ->when($market, fn ($q) => $q->market($market))
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        if ($stocks->isEmpty()) {
            return [];
        }

        $items = [];

        foreach ($stocks as $i => $stock) {
            /** @var Stock $stock */
            $template = $this->templates[$i % count($this->templates)];
            $title = str_replace(
                ['{name}', '{symbol}'],
                [$stock->name, $stock->symbol],
                $template,
            );

            $publishedAt = CarbonImmutable::now()->subMinutes(mt_rand(1, 720));

            $items[] = new NewsItemData(
                title: $title,
                summary: $title.'. '.Str::limit(fake()->paragraph(), 160),
                content: fake()->text(600),
                url: 'https://news.example.test/'.Str::slug($title).'-'.Str::random(6),
                imageUrl: null,
                publishedAt: $publishedAt,
                market: $stock->market,
                sourceKey: 'synthetic',
                sourceName: 'TradeNews Synthetic Wire',
                relatedSymbols: [$stock->symbol],
            );
        }

        return $items;
    }
}
