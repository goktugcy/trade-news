<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateStockAnalysisJob;
use App\Models\Stock;
use App\Services\Ai\StockAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class GenerateStockAnalysesCommand extends Command
{
    protected $signature = 'tradenews:generate-stock-analyses
        {--scope=hourly : hourly (watchlist + recent important-news stocks) | daily (all active) | all}
        {--symbols= : Comma-separated symbols to analyze}
        {--sync : Run inline instead of dispatching a job}';

    protected $description = 'Generate cached AI forecasts/signals for stocks (off the HTTP path)';

    public function handle(StockAnalyzer $analyzer): int
    {
        if (! $analyzer->isEnabled()) {
            $this->warn('Stock analysis task is disabled or unhealthy; nothing generated.');

            return self::SUCCESS;
        }

        $ids = $this->stockIds();

        if ($ids === []) {
            $this->info('No stocks matched the selected scope.');

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            (new GenerateStockAnalysisJob($ids))->handle($analyzer);
            $this->info('Generated analyses for '.count($ids).' stock(s).');

            return self::SUCCESS;
        }

        GenerateStockAnalysisJob::dispatch($ids);
        $this->info('Dispatched analysis job for '.count($ids).' stock(s).');

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function stockIds(): array
    {
        $symbols = trim((string) $this->option('symbols'));

        if ($symbols !== '') {
            return Stock::query()->active()
                ->whereIn('symbol', array_map('trim', explode(',', mb_strtoupper($symbols))))
                ->pluck('id')->all();
        }

        return match ($this->option('scope')) {
            'daily', 'all' => Stock::query()->active()->pluck('id')->all(),
            default => $this->hourlyScopeIds(),
        };
    }

    /**
     * Watchlisted stocks plus those tied to recent high-importance news.
     *
     * @return array<int, int>
     */
    private function hourlyScopeIds(): array
    {
        return Stock::query()
            ->active()
            ->where(function (Builder $q): void {
                $q->whereHas('watchers')
                    ->orWhereHas('news', fn (Builder $n) => $n
                        ->where('importance_score', '>=', 50)
                        ->where('published_at', '>=', now()->subDay()));
            })
            ->pluck('id')
            ->all();
    }
}
