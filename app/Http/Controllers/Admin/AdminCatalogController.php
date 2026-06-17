<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ProviderStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiProvider;
use App\Models\NewsSource;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin management for the supporting catalog entities: news sources,
 * API providers, and users.
 */
class AdminCatalogController extends Controller
{
    // ---------------- News sources ----------------

    public function newsSources(): Response
    {
        return Inertia::render('admin/NewsSources', [
            'sources' => NewsSource::query()
                ->withCount('newsItems')
                ->orderBy('name')
                ->get()
                ->map(fn (NewsSource $s) => [
                    'id' => $s->id,
                    'key' => $s->key,
                    'name' => $s->name,
                    'provider' => $s->provider,
                    'market' => $s->market,
                    'is_active' => $s->is_active,
                    'news_items_count' => $s->news_items_count,
                ]),
        ]);
    }

    public function toggleNewsSource(NewsSource $newsSource): RedirectResponse
    {
        $newsSource->update(['is_active' => ! $newsSource->is_active]);

        return back();
    }

    // ---------------- API providers ----------------

    public function apiProviders(): Response
    {
        return Inertia::render('admin/ApiProviders', [
            'providers' => ApiProvider::query()
                ->orderBy('type')
                ->orderBy('priority')
                ->get()
                ->map(fn (ApiProvider $p) => [
                    'id' => $p->id,
                    'key' => $p->key,
                    'name' => $p->name,
                    'type' => $p->type->value,
                    'status' => $p->status->value,
                    'status_color' => $p->status->color(),
                    'is_active' => $p->is_active,
                    'priority' => $p->priority,
                    'base_url' => $p->base_url,
                    'last_latency_ms' => $p->last_latency_ms,
                    'last_error' => $p->last_error,
                    'last_checked_at' => $p->last_checked_at?->diffForHumans(),
                ]),
            'statuses' => array_map(fn (ProviderStatus $s) => ['value' => $s->value, 'label' => $s->label()], ProviderStatus::cases()),
        ]);
    }

    public function updateApiProvider(Request $request, ApiProvider $apiProvider): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['boolean'],
            'priority' => ['integer', 'min:1', 'max:999'],
            'status' => [Rule::in(array_column(ProviderStatus::cases(), 'value'))],
        ]);

        $apiProvider->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Provider updated.']);

        return back();
    }

    // ---------------- Users ----------------

    public function users(Request $request): Response
    {
        $search = trim($request->string('q')->toString());

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'ILIKE', "%{$search}%")->orWhere('email', 'ILIKE', "%{$search}%"))
            ->withCount(['watchlist', 'notificationRules'])
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_admin' => $u->is_admin,
                'watchlist_count' => $u->watchlist_count,
                'rules_count' => $u->notification_rules_count,
                'created_at' => $u->created_at?->toDateString(),
            ]);

        return Inertia::render('admin/Users', [
            'users' => $users,
            'filters' => ['q' => $search ?: null],
        ]);
    }

    public function toggleAdmin(Request $request, User $user): RedirectResponse
    {
        // Don't let an admin demote themselves and get locked out.
        if ($user->id === $request->user()->id) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'You cannot change your own admin status.']);

            return back();
        }

        $user->update(['is_admin' => ! $user->is_admin]);

        return back();
    }
}
