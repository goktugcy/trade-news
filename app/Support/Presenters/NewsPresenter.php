<?php

declare(strict_types=1);

namespace App\Support\Presenters;

use App\Models\NewsItem;

/**
 * Shapes news items into the array contract consumed by the Vue feed.
 */
class NewsPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function card(NewsItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'summary' => $item->summary,
            'url' => $item->url,
            'image_url' => $item->image_url,
            'market' => $item->market?->value,
            'sentiment' => $item->sentiment?->value,
            'sentiment_color' => $item->sentiment?->color(),
            'importance' => $item->importance_score,
            'published_at' => $item->published_at?->toIso8601String(),
            'published_for_humans' => $item->published_at?->diffForHumans(),
            'source' => $item->relationLoaded('source') ? $item->source?->name : null,
            'stocks' => $item->relationLoaded('stocks')
                ? $item->stocks->map(fn ($stock) => [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'market' => $stock->market->value,
                ])->all()
                : [],
        ];
    }

    /**
     * @param  iterable<int, NewsItem>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[] = self::card($item);
        }

        return $out;
    }
}
