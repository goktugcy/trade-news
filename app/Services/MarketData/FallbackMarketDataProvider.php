<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\DataTransferObjects\CandleData;
use App\DataTransferObjects\QuoteData;
use App\Enums\Timeframe;
use App\Models\Stock;
use Throwable;

class FallbackMarketDataProvider implements MarketDataProviderInterface
{
    private ?string $lastQuoteProviderKey = null;

    /** @var array<string, string> */
    private array $lastCandleProviderKeys = [];

    /**
     * @param  array<int, MarketDataProviderInterface>  $providers
     */
    public function __construct(
        private readonly array $providers,
    ) {}

    public function key(): string
    {
        if ($this->lastQuoteProviderKey !== null) {
            return $this->lastQuoteProviderKey;
        }

        if ($this->providers === []) {
            return 'none';
        }

        return $this->providers[0]->key();
    }

    public function getQuote(Stock $stock): ?QuoteData
    {
        $this->lastQuoteProviderKey = null;

        foreach ($this->providers as $provider) {
            try {
                $quote = $provider->getQuote($stock);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($quote instanceof QuoteData) {
                $this->lastQuoteProviderKey = $provider->key();

                return $quote;
            }
        }

        return null;
    }

    /**
     * @return array<int, CandleData>
     */
    public function getCandles(Stock $stock, Timeframe $timeframe, int $limit = 120): array
    {
        unset($this->lastCandleProviderKeys[$timeframe->value]);

        foreach ($this->providers as $provider) {
            try {
                $candles = $provider->getCandles($stock, $timeframe, $limit);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($candles !== []) {
                $this->lastCandleProviderKeys[$timeframe->value] = $provider->key();

                return $candles;
            }
        }

        return [];
    }

    public function lastQuoteProviderKey(): ?string
    {
        return $this->lastQuoteProviderKey;
    }

    public function lastCandleProviderKey(Timeframe $timeframe): ?string
    {
        return $this->lastCandleProviderKeys[$timeframe->value] ?? null;
    }
}
