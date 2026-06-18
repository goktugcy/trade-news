<?php

declare(strict_types=1);

use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Models\ApiProvider;
use App\Models\ProviderEvent;
use App\Models\User;
use App\Services\Providers\ProviderHealthService;
use Inertia\Testing\AssertableInertia as Assert;

function provider(array $attrs = []): ApiProvider
{
    return ApiProvider::factory()->create(array_merge([
        'key' => 'finnhub',
        'type' => ProviderType::MarketData,
        'status' => ProviderStatus::Operational,
        'is_active' => true,
        'auto_recovery' => true,
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
    ], $attrs));
}

it('degrades then goes down after consecutive failures', function () {
    $p = provider();
    $health = app(ProviderHealthService::class);

    $health->recordFailure('finnhub', 'HTTP 500');
    $health->recordFailure('finnhub', 'HTTP 500'); // degraded_after = 2
    expect($p->fresh()->status)->toBe(ProviderStatus::Degraded);

    $health->recordFailure('finnhub', 'HTTP 500');
    $health->recordFailure('finnhub', 'HTTP 500'); // down_after = 4
    expect($p->fresh()->status)->toBe(ProviderStatus::Down);

    // Each transition is logged.
    expect(ProviderEvent::where('api_provider_id', $p->id)->count())->toBe(2);
});

it('auto-recovers to operational after enough successes', function () {
    $p = provider(['status' => ProviderStatus::Down, 'consecutive_failures' => 5]);
    $health = app(ProviderHealthService::class);

    $health->recordSuccess('finnhub');
    $health->recordSuccess('finnhub'); // recover_after = 2

    expect($p->fresh()->status)->toBe(ProviderStatus::Operational);
});

it('does not auto-recover when auto_recovery is off', function () {
    $p = provider(['status' => ProviderStatus::Down, 'auto_recovery' => false]);
    $health = app(ProviderHealthService::class);

    $health->recordSuccess('finnhub');
    $health->recordSuccess('finnhub');

    expect($p->fresh()->status)->toBe(ProviderStatus::Down);
});

it('notifies admins on a status transition', function () {
    $admin = User::factory()->admin()->create();
    provider();

    app(ProviderHealthService::class)->recordFailure('finnhub', 'HTTP 500');
    app(ProviderHealthService::class)->recordFailure('finnhub', 'HTTP 500');

    expect($admin->userNotifications()->where('category', 'provider')->count())->toBeGreaterThan(0);
});

it('ignores disabled providers', function () {
    $p = provider(['is_active' => false, 'status' => ProviderStatus::Disabled]);

    app(ProviderHealthService::class)->recordFailure('finnhub', 'HTTP 500');

    expect($p->fresh()->status)->toBe(ProviderStatus::Disabled);
});

it('lets an admin create, update and delete a provider', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/providers', [
        'key' => 'fmp', 'name' => 'FMP', 'type' => 'market_data',
        'capabilities' => ['list', 'profiles'], 'markets' => ['NASDAQ'],
        'priority' => 40, 'refresh_interval_minutes' => 60, 'fetch_limit' => 100,
        'auto_recovery' => true, 'is_active' => true,
    ])->assertRedirect();

    $created = ApiProvider::where('key', 'fmp')->first();
    expect($created)->not->toBeNull()->and($created->capabilities)->toContain('profiles');

    $this->actingAs($admin)->delete("/admin/providers/{$created->id}")->assertRedirect();
    expect(ApiProvider::where('key', 'fmp')->exists())->toBeFalse();
});

it('logs an event when an admin disables a provider', function () {
    $admin = User::factory()->admin()->create();
    $p = provider();

    $this->actingAs($admin)->put("/admin/providers/{$p->id}", [
        'key' => $p->key, 'name' => $p->name, 'type' => $p->type->value,
        'priority' => $p->priority, 'refresh_interval_minutes' => 5, 'fetch_limit' => 50,
        'is_active' => false, 'auto_recovery' => true,
    ])->assertRedirect();

    expect($p->fresh()->status)->toBe(ProviderStatus::Disabled)
        ->and(ProviderEvent::where('api_provider_id', $p->id)->where('to_status', 'disabled')->exists())->toBeTrue();
});

it('renders the provider events page for admins', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/provider-events')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/ProviderEvents')->has('events'));
});
