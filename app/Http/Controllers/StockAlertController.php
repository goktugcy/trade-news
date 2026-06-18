<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockAlertRequest;
use App\Models\StockAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockAlertController extends Controller
{
    public function store(StoreStockAlertRequest $request): RedirectResponse
    {
        $request->user()->stockAlerts()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alert created.']);

        return back();
    }

    public function update(StoreStockAlertRequest $request, StockAlert $stockAlert): RedirectResponse
    {
        $this->authorizeOwner($request, $stockAlert);

        $stockAlert->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alert updated.']);

        return back();
    }

    public function destroy(Request $request, StockAlert $stockAlert): RedirectResponse
    {
        $this->authorizeOwner($request, $stockAlert);

        $stockAlert->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alert deleted.']);

        return back();
    }

    private function authorizeOwner(Request $request, StockAlert $stockAlert): void
    {
        abort_unless($stockAlert->user_id === $request->user()->id, 403);
    }
}
