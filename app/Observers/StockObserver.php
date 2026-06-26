<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Stock;
use App\Services\News\StockAliasService;

/**
 * Keeps the deterministic alias index in sync: rebuilds a stock's stock_aliases
 * rows whenever its symbol, name or editable aliases change (and on create).
 */
class StockObserver
{
    public function __construct(private readonly StockAliasService $aliases) {}

    public function saved(Stock $stock): void
    {
        if ($stock->wasRecentlyCreated || $stock->wasChanged(['symbol', 'name', 'aliases'])) {
            $this->aliases->rebuildFor($stock);
        }
    }

    public function deleted(Stock $stock): void
    {
        // stock_aliases rows cascade on delete; nothing to do.
    }
}
