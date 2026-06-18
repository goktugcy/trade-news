<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AlertType;
use App\Enums\Market;
use App\Enums\NotificationInterval;
use App\Enums\Sentiment;
use App\Http\Requests\StoreNotificationRuleRequest;
use App\Models\NotificationRule;
use App\Models\StockAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $rules = $request->user()->notificationRules()
            ->latest()
            ->get()
            ->map(fn (NotificationRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'interval_minutes' => $rule->interval_minutes,
                'interval_label' => $rule->interval()->label(),
                'markets' => $rule->markets,
                'sentiments' => $rule->sentiments,
                'only_watchlist' => $rule->only_watchlist,
                'min_importance' => $rule->min_importance,
                'is_active' => $rule->is_active,
                'last_dispatched_at' => $rule->last_dispatched_at?->diffForHumans(),
            ]);

        $stockAlerts = $request->user()->stockAlerts()
            ->with('stock:id,symbol,name,market')
            ->get()
            ->map(fn (StockAlert $a) => [
                'id' => $a->id,
                'stock_id' => $a->stock_id,
                'symbol' => $a->stock?->symbol,
                'type' => $a->type->value,
                'type_label' => $a->type->label(),
                'threshold' => $a->threshold,
                'cooldown_minutes' => $a->cooldown_minutes,
                'is_active' => $a->is_active,
                'notify_in_app' => $a->notify_in_app,
                'notify_telegram' => $a->notify_telegram,
                'last_triggered_at' => $a->last_triggered_at?->diffForHumans(),
            ]);

        $watchlistStocks = $request->user()->watchedStocks()
            ->get(['stocks.id', 'symbol', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'symbol' => $s->symbol, 'name' => $s->name]);

        return Inertia::render('alerts/Index', [
            'rules' => $rules,
            'options' => $this->options(),
            'stockAlerts' => $stockAlerts,
            'alertTypes' => AlertType::options(),
            'watchlistStocks' => $watchlistStocks,
            'telegramConnected' => (bool) $request->user()->telegramIntegration?->isActive(),
        ]);
    }

    public function store(StoreNotificationRuleRequest $request): RedirectResponse
    {
        $request->user()->notificationRules()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alert rule created.']);

        return back();
    }

    public function update(StoreNotificationRuleRequest $request, NotificationRule $notificationRule): RedirectResponse
    {
        $this->authorize('update', $notificationRule);

        $notificationRule->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alert rule updated.']);

        return back();
    }

    public function destroy(Request $request, NotificationRule $notificationRule): RedirectResponse
    {
        $this->authorize('delete', $notificationRule);

        $notificationRule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alert rule deleted.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'intervals' => NotificationInterval::options(),
            'markets' => Market::options(),
            'sentiments' => array_map(fn (Sentiment $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ], Sentiment::cases()),
        ];
    }
}
