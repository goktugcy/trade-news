<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Timeframe;
use App\Models\NewsItem;
use App\Models\Notification;
use App\Models\StockPrice;
use App\Models\SystemJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Prunes stale intraday candles, old news, and delivered notifications per the
 * retention windows in config/tradenews.php. Also clears duplicate news that
 * slipped past the hash guard (same title + url).
 */
class CleanupOldDataJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function handle(): void
    {
        $retention = config('tradenews.retention');

        // Intraday prices older than the window (keep daily candles forever).
        StockPrice::query()
            ->where('timeframe', '!=', Timeframe::OneDay->value)
            ->where('price_at', '<', now()->subDays($retention['intraday_prices_days']))
            ->delete();

        NewsItem::query()
            ->where('published_at', '<', now()->subDays($retention['news_days']))
            ->delete();

        Notification::query()
            ->where('created_at', '<', now()->subDays($retention['notifications_days']))
            ->delete();

        // Trim the system-job heartbeat log.
        SystemJob::query()
            ->where('created_at', '<', now()->subDays(14))
            ->delete();

        $this->removeDuplicateNews();
    }

    /**
     * Defensive de-dup: collapse news rows sharing the same hash, keeping the
     * earliest. (The unique index normally prevents these.)
     */
    private function removeDuplicateNews(): void
    {
        $duplicateHashes = DB::table('news_items')
            ->selectRaw('hash, MIN(id) as keep_id')
            ->groupBy('hash')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateHashes as $row) {
            NewsItem::query()
                ->where('hash', $row->hash)
                ->where('id', '!=', $row->keep_id)
                ->delete();
        }
    }
}
