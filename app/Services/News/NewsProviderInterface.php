<?php

declare(strict_types=1);

namespace App\Services\News;

use App\DataTransferObjects\NewsItemData;
use App\Enums\Market;

interface NewsProviderInterface
{
    /**
     * A stable key identifying this provider (matches api_providers.key).
     */
    public function key(): string;

    /**
     * Fetch the latest news for a market (or all markets when null).
     *
     * @return array<int, NewsItemData>
     */
    public function fetchLatest(?Market $market = null, int $limit = 50): array;
}
