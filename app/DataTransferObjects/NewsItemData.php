<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\Market;
use App\Models\NewsItem;
use Carbon\CarbonImmutable;

/**
 * A normalized news item as returned by any news provider, before it is
 * persisted and matched to stocks.
 */
final readonly class NewsItemData
{
    /**
     * @param  array<int, string>  $relatedSymbols  symbols the provider already tagged
     */
    public function __construct(
        public string $title,
        public ?string $summary,
        public ?string $content,
        public ?string $url,
        public ?string $imageUrl,
        public ?CarbonImmutable $publishedAt,
        public ?Market $market,
        public string $sourceKey,
        public ?string $sourceName = null,
        public array $relatedSymbols = [],
    ) {}

    public function hash(): string
    {
        return NewsItem::makeHash($this->title, $this->url, $this->sourceKey);
    }
}
