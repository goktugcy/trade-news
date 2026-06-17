<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStockRequest;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminStockController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());

        $stocks = Stock::query()
            ->when($search !== '', fn (Builder $q) => $q->search($search))
            ->orderBy('market')
            ->orderBy('symbol')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Stock $s) => [
                'id' => $s->id,
                'symbol' => $s->symbol,
                'name' => $s->name,
                'market' => $s->market->value,
                'sector' => $s->sector,
                'currency' => $s->currency,
                'aliases' => $s->aliases ?? [],
                'keywords' => $s->keywords ?? [],
                'is_active' => $s->is_active,
            ]);

        return Inertia::render('admin/Stocks', [
            'stocks' => $stocks,
            'filters' => ['q' => $search ?: null],
            'options' => ['markets' => Market::options()],
        ]);
    }

    public function store(StoreStockRequest $request): RedirectResponse
    {
        Stock::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Stock created.']);

        return back();
    }

    public function update(StoreStockRequest $request, Stock $stock): RedirectResponse
    {
        $stock->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Stock updated.']);

        return back();
    }

    public function destroy(Stock $stock): RedirectResponse
    {
        $stock->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Stock deleted.']);

        return back();
    }
}
