<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\News\StockAliasService;
use Illuminate\Console\Command;

class RebuildStockAliasesCommand extends Command
{
    protected $signature = 'tradenews:rebuild-stock-aliases';

    protected $description = 'Rebuild the deterministic stock_aliases matching index for every stock';

    public function handle(StockAliasService $aliases): int
    {
        $count = $aliases->rebuildAll();

        $this->info("Rebuilt alias index for {$count} stock(s).");

        return self::SUCCESS;
    }
}
