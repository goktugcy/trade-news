<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Models\Stock;
use App\Services\Sync\UsIndexUniverseService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class PruneNasdaqUniverseCommand extends Command
{
    protected $signature = 'tradenews:prune-nasdaq-universe
        {--dry-run : Report deletions without deleting records}
        {--source=auto : Universe source: auto, fmp, or fallback}
        {--force-live : Bypass the cached FMP universe result}';

    protected $description = 'Delete NASDAQ-market stocks outside the Nasdaq-100 + S&P 500 universe';

    public function handle(UsIndexUniverseService $universe): int
    {
        $source = mb_strtolower(trim((string) $this->option('source')));

        if (! in_array($source, [UsIndexUniverseService::SOURCE_AUTO, UsIndexUniverseService::SOURCE_FMP, UsIndexUniverseService::SOURCE_FALLBACK], true)) {
            $this->error('Invalid --source value. Use auto, fmp, or fallback.');

            return self::FAILURE;
        }

        try {
            $result = $universe->resolve($source, (bool) $this->option('force-live'));
        } catch (Throwable $exception) {
            $this->error('Unable to resolve NASDAQ universe: '.$exception->getMessage());

            return self::FAILURE;
        }

        $allowedSymbols = array_fill_keys($result['symbols'], true);
        [$prunableCount, $sampleSymbols] = $this->scanPrunable($allowedSymbols);

        $totalBefore = Stock::query()->where('market', Market::NASDAQ->value)->count();
        $activeBefore = Stock::query()->where('market', Market::NASDAQ->value)->where('is_active', true)->count();

        $this->table(['Metric', 'Value'], [
            ['Universe source', $result['source']],
            ['Universe symbols', (string) count($result['symbols'])],
            ['S&P 500 symbols', (string) $result['sp500_count']],
            ['Nasdaq-100 symbols', (string) $result['nasdaq100_count']],
            ['NASDAQ rows before', (string) $totalBefore],
            ['Active NASDAQ rows before', (string) $activeBefore],
            ['Rows to delete', (string) $prunableCount],
        ]);

        if (($result['fallback_reason'] ?? null) !== null) {
            $this->warn('Fallback reason: '.$result['fallback_reason']);
        }

        if ($sampleSymbols !== []) {
            $this->line('Delete sample: '.implode(', ', $sampleSymbols));
        }

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run complete. No records were deleted.');

            return self::SUCCESS;
        }

        $deleted = $this->deletePrunable($allowedSymbols);
        $totalAfter = Stock::query()->where('market', Market::NASDAQ->value)->count();
        $activeAfter = Stock::query()->where('market', Market::NASDAQ->value)->where('is_active', true)->count();

        $this->info("Deleted {$deleted} NASDAQ stock row(s) outside Nasdaq-100 + S&P 500.");
        $this->line("NASDAQ rows after: {$totalAfter}; active after: {$activeAfter}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, true>  $allowedSymbols
     * @return array{0: int, 1: array<int, string>}
     */
    private function scanPrunable(array $allowedSymbols): array
    {
        $count = 0;
        $sample = [];

        $this->nasdaqStocksQuery()->chunkById(500, function (Collection $stocks) use ($allowedSymbols, &$count, &$sample): void {
            foreach ($stocks as $stock) {
                $symbol = UsIndexUniverseService::normalizeSymbol((string) $stock->symbol);

                if (isset($allowedSymbols[$symbol])) {
                    continue;
                }

                $count++;

                if (count($sample) < 20) {
                    $sample[] = (string) $stock->symbol;
                }
            }
        });

        return [$count, $sample];
    }

    /**
     * @param  array<string, true>  $allowedSymbols
     */
    private function deletePrunable(array $allowedSymbols): int
    {
        $deleted = 0;

        $this->nasdaqStocksQuery()->chunkById(500, function (Collection $stocks) use ($allowedSymbols, &$deleted): void {
            $ids = [];

            foreach ($stocks as $stock) {
                $symbol = UsIndexUniverseService::normalizeSymbol((string) $stock->symbol);

                if (! isset($allowedSymbols[$symbol])) {
                    $ids[] = (int) $stock->id;
                }
            }

            if ($ids !== []) {
                $deleted += Stock::query()->whereKey($ids)->delete();
            }
        });

        return $deleted;
    }

    private function nasdaqStocksQuery(): Builder
    {
        return Stock::query()
            ->select(['id', 'symbol'])
            ->where('market', Market::NASDAQ->value)
            ->orderBy('id');
    }
}
