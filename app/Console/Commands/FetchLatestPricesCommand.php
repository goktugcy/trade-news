<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProviderType;
use App\Jobs\FetchStockPricesJob;
use App\Models\Stock;
use App\Models\SystemJob;
use App\Services\Providers\ApiProviderRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class FetchLatestPricesCommand extends Command
{
    protected $signature = 'tradenews:fetch-prices
        {--market= : Limit to BIST or NASDAQ}
        {--symbols= : Comma-separated symbols to fetch}
        {--limit= : Maximum number of stocks to dispatch this run}
        {--all : Dispatch every matching active stock, ignoring the provider fetch limit}
        {--force : Ignore the provider refresh interval + freshness window and fetch now}
        {--fresh-minutes= : Skip stocks priced within this many minutes (default config)}
        {--random : Randomize the selected stock slice instead of stale-first}';

    protected $description = 'Dispatch price-fetch jobs for active stocks (least-recently-priced first)';

    public function handle(ApiProviderRegistry $providers): int
    {
        $force = (bool) $this->option('force') || (bool) $this->option('all');

        $count = SystemJob::track('tradenews:fetch-prices', function (SystemJob $job) use ($providers, $force): int {
            $dispatched = 0;
            $symbols = $this->symbols();

            // With --force/--all we use every active provider; otherwise only the due ones.
            $targetProviders = $force
                ? $providers->activeProviderRowsForCapabilities(
                    ProviderType::MarketData,
                    ApiProviderRegistry::MARKET_DATA_FETCH_CAPABILITIES,
                    concreteOnly: true,
                )
                : $providers->dueProviderRowsForCapabilities(
                    ProviderType::MarketData,
                    ApiProviderRegistry::MARKET_DATA_FETCH_CAPABILITIES,
                    concreteOnly: true,
                );

            if ($targetProviders->isEmpty()) {
                $job->update(['meta' => [
                    'dispatched' => 0,
                    'provider_keys' => [],
                    'skipped' => $providers->hasActiveProviderRowsForCapabilities(
                        ProviderType::MarketData,
                        ApiProviderRegistry::MARKET_DATA_FETCH_CAPABILITIES,
                        concreteOnly: true,
                    ) ? 'not_due' : 'no_active_price_provider',
                ]]);

                return 0;
            }

            // --all ignores the per-run cap and dispatches the entire matching universe.
            $limit = $this->option('all')
                ? null
                : $this->limit($providers->fetchLimitForCapabilities(
                    ProviderType::MarketData,
                    ApiProviderRegistry::MARKET_DATA_FETCH_CAPABILITIES,
                ));

            $freshMinutes = $this->freshMinutes();

            $query = Stock::query()
                ->active()
                ->when($this->option('market'), fn (Builder $q) => $q->market($this->option('market')))
                ->when($symbols !== [], fn (Builder $q) => $q->whereIn('symbol', $symbols))
                // Skip symbols already priced within the freshness window so the
                // budget covers stocks no provider has fetched yet. Forced or
                // explicit-symbol runs bypass this.
                ->when(! $force && $symbols === [], fn (Builder $q) => $q->stale($freshMinutes))
                ->select('id', 'symbol');

            $this->dispatchFromQuery($query, $dispatched, $limit, $force);
            $providers->markFetched($targetProviders);

            $job->update(['meta' => [
                'dispatched' => $dispatched,
                'limit' => $limit,
                'all' => (bool) $this->option('all'),
                'force' => $force,
                'fresh_minutes' => $freshMinutes,
                'random' => (bool) $this->option('random'),
                'symbols' => $symbols,
                'provider_keys' => $targetProviders->pluck('key')->values()->all(),
            ]]);

            return $dispatched;
        });

        $this->info("Dispatched {$count} price-fetch job(s).");

        if ($count > 200) {
            $this->warn('Heads up: free API tiers are rate-limited (Finnhub ~60/min, Twelve Data ~8/min). '
                .'A large batch will take a while to drain through the queue worker.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function symbols(): array
    {
        return array_values(array_filter(array_map(
            fn (string $symbol): string => Str::upper(trim($symbol)),
            explode(',', (string) $this->option('symbols')),
        )));
    }

    private function limit(?int $default = null): ?int
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return $default;
        }

        return max(1, (int) $limit);
    }

    private function freshMinutes(): int
    {
        $option = $this->option('fresh-minutes');

        if ($option !== null && $option !== '') {
            return max(1, (int) $option);
        }

        return max(1, (int) config('tradenews.market_data.fresh_within_minutes', 10));
    }

    /**
     * Dispatch a price-fetch job per stock. Defaults to least-recently-priced
     * first so successive runs rotate through the whole universe instead of
     * re-fetching the same head of the table every time.
     *
     * @param  Builder<Stock>  $query
     */
    private function dispatchFromQuery(Builder $query, int &$dispatched, ?int $limit, bool $force): void
    {
        if ($this->option('random')) {
            $query->inRandomOrder();
        } else {
            $query
                ->orderByRaw('(select max(created_at) from stock_prices where stock_prices.stock_id = stocks.id) asc nulls first')
                ->orderBy('id');
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $stock) {
            FetchStockPricesJob::dispatch($stock->id, $force);
            $dispatched++;
        }
    }
}
