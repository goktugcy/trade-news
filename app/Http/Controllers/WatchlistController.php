<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreWatchlistRequest;
use App\Models\Watchlist;
use App\Support\Presenters\StockPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WatchlistController extends Controller
{
    /**
     * Watchlist management page.
     */
    public function index(Request $request): Response
    {
        $items = $request->user()->watchlist()
            ->with('stock.latestPrice')
            ->get()
            ->map(fn (Watchlist $item) => StockPresenter::row($item->stock, [
                'in_watchlist' => true,
                'alerts_enabled' => $item->alerts_enabled,
                'watchlist_id' => $item->id,
            ]));

        return Inertia::render('watchlist/Index', [
            'items' => $items,
        ]);
    }

    /**
     * Add a stock to the watchlist.
     */
    public function store(StoreWatchlistRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->watchlist()->firstOrCreate(
            ['stock_id' => $request->integer('stock_id')],
            ['position' => (int) $user->watchlist()->max('position') + 1, 'alerts_enabled' => true],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added to watchlist.']);

        return back();
    }

    /**
     * Toggle the per-stock Telegram alert flag.
     */
    public function toggleAlert(Request $request, Watchlist $watchlist): RedirectResponse
    {
        $this->authorize('update', $watchlist);

        $watchlist->update(['alerts_enabled' => ! $watchlist->alerts_enabled]);

        return back();
    }

    /**
     * Remove a stock from the watchlist.
     */
    public function destroy(Request $request, Watchlist $watchlist): RedirectResponse
    {
        $this->authorize('delete', $watchlist);

        $watchlist->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Removed from watchlist.']);

        return back();
    }
}
