<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Enums\NotificationInterval;
use App\Enums\Sentiment;
use App\Http\Requests\StoreNotificationRuleRequest;
use App\Models\NotificationRule;
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

        return Inertia::render('alerts/Index', [
            'rules' => $rules,
            'options' => $this->options(),
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
