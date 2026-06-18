<?php

declare(strict_types=1);

namespace App\Services\News;

use App\DataTransferObjects\NewsItemData;
use App\Enums\Market;
use Throwable;

class FallbackNewsProvider implements NewsProviderInterface
{
    private ?string $lastProviderKey = null;

    /**
     * @param  array<int, NewsProviderInterface>  $providers
     */
    public function __construct(
        private readonly array $providers,
    ) {}

    public function key(): string
    {
        if ($this->lastProviderKey !== null) {
            return $this->lastProviderKey;
        }

        if ($this->providers === []) {
            return 'none';
        }

        return $this->providers[0]->key();
    }

    /**
     * @return array<int, NewsItemData>
     */
    public function fetchLatest(?Market $market = null, int $limit = 50): array
    {
        $this->lastProviderKey = null;

        foreach ($this->providers as $provider) {
            try {
                $items = $provider->fetchLatest($market, $limit);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($items !== []) {
                $this->lastProviderKey = $provider->key();

                return $items;
            }
        }

        return [];
    }
}
