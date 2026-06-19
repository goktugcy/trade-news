<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MarketData\MarketSummaryService;
use Illuminate\Console\Command;

class WarmMarketSummaryCommand extends Command
{
    protected $signature = 'tradenews:warm-market-summary';

    protected $description = 'Recompute and cache the scrolling ticker + top movers (keeps web requests O(1))';

    public function handle(MarketSummaryService $summary): int
    {
        $summary->warm();

        $this->info('Market summary cache warmed.');

        return self::SUCCESS;
    }
}
