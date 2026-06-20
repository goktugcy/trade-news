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
    public static function card(NewsItem $item, ?string $locale = null): array
    {
        $translation = is_string($locale) ? $item->translationFor($locale) : null;
        $summary = $translation?->summary ?: ($item->ai_summary ?: $item->summary);

        return [
            'id' => $item->id,
            'title' => $translation?->title ?: $item->title,
            'summary' => $summary,
            'has_ai_summary' => $item->ai_summary !== null,
            'has_translation' => $translation !== null,
            'translation_locale' => $translation?->locale,
            'url' => $item->url,
            'image_url' => $item->image_url,
            'market' => $item->market?->value,
            'sentiment' => $item->sentiment?->value,
            'sentiment_color' => $item->sentiment?->color(),
            'importance' => $item->importance_score,
            'published_at' => $item->published_at?->toIso8601String(),
            'published_for_humans' => $item->published_at?->diffForHumans(),
            'source' => $item->relationLoaded('source') ? $item->source?->name : null,
            'source_count' => $item->source_count,
            // Per-user interaction state (present only when eager-loaded for the current user).
            'reaction' => $item->relationLoaded('reactionForUser') ? $item->reactionForUser?->value : null,
            'is_saved' => $item->relationLoaded('savedForUser') ? $item->savedForUser !== null : false,
            'like_count' => $item->likes_count ?? 0,
            'dislike_count' => $item->dislikes_count ?? 0,
            // Every original outlet this (possibly merged) story came from.
            'sources' => $item->relationLoaded('sources')
                ? $item->sources->map(fn ($s) => [
                    'name' => $s->relationLoaded('source') ? $s->source?->name : null,
                    'url' => $s->url,
                ])->filter(fn ($s) => $s['name'] !== null)->values()->all()
                : [],
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
    public static function collection(iterable $items, ?string $locale = null): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[] = self::card($item, $locale);
        }

        return $out;
    }
}
