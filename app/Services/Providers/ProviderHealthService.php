<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Enums\NotificationCategory;
use App\Enums\ProviderStatus;
use App\Models\ApiProvider;
use App\Models\ProviderEvent;
use App\Services\Notification\NotificationCenter;

/**
 * The provider status state machine. Successful/failed requests (driven by the
 * health-probe command, which makes a representative request per provider) move
 * a provider between Operational → Degraded → Down and back, auto-recovering
 * when enabled. Every transition is logged to provider_events and notified to
 * admins. Disabled providers are left untouched.
 */
class ProviderHealthService
{
    public function __construct(
        private readonly NotificationCenter $notifications,
    ) {}

    public function recordSuccess(string $key, ?string $context = null): void
    {
        $provider = $this->activeProvider($key);

        if ($provider === null) {
            return;
        }

        $provider->consecutive_failures = 0;
        $provider->consecutive_successes++;
        $provider->last_error = null;

        $target = $provider->status;

        // Unknown resolves on the first success; Down/Degraded recover once we
        // see enough consecutive successes (only when auto-recovery is on).
        if ($provider->status === ProviderStatus::Unknown) {
            $target = ProviderStatus::Operational;
        } elseif (
            in_array($provider->status, [ProviderStatus::Down, ProviderStatus::Degraded], true)
            && $provider->auto_recovery
            && $provider->consecutive_successes >= $this->threshold('recover_after')
        ) {
            $target = ProviderStatus::Operational;
        }

        $this->transition($provider, $target, $context ?? 'request_succeeded');
    }

    public function recordFailure(string $key, string $error): void
    {
        $provider = $this->activeProvider($key);

        if ($provider === null) {
            return;
        }

        $provider->consecutive_successes = 0;
        $provider->consecutive_failures++;
        $provider->last_error = mb_substr($error, 0, 1000);

        $target = match (true) {
            $provider->consecutive_failures >= $this->threshold('down_after') => ProviderStatus::Down,
            $provider->consecutive_failures >= $this->threshold('degraded_after') => ProviderStatus::Degraded,
            default => $provider->status === ProviderStatus::Operational ? ProviderStatus::Operational : $provider->status,
        };

        $this->transition($provider, $target, 'request_failed: '.mb_substr($error, 0, 120));

        // Heads-up the first time a provider starts rate-limiting.
        if (str_contains($error, '429') && $provider->consecutive_failures === 1) {
            $this->notifications->toAdmins(
                NotificationCategory::Provider,
                'provider_rate_limit',
                "{$provider->name} is being rate-limited",
                'The provider returned HTTP 429. Consider lowering its fetch limit or refresh interval.',
                ['provider' => $provider->key],
                '/admin/providers',
            );
        }
    }

    /**
     * Manually flip enable/disable from the admin panel.
     */
    public function setDisabled(ApiProvider $provider, bool $disabled): void
    {
        if ($disabled) {
            $provider->is_active = false;
            $this->transition($provider, ProviderStatus::Disabled, 'manually_disabled');
        } else {
            $provider->is_active = true;
            $provider->consecutive_failures = 0;
            $this->transition($provider, ProviderStatus::Unknown, 'manually_enabled');
        }
    }

    private function transition(ApiProvider $provider, ProviderStatus $to, string $reason): void
    {
        $from = $provider->status;
        $changed = $from !== $to;
        $provider->status = $to;
        $provider->save();

        if (! $changed) {
            return;
        }

        ProviderEvent::create([
            'api_provider_id' => $provider->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'context' => [
                'consecutive_failures' => $provider->consecutive_failures,
                'consecutive_successes' => $provider->consecutive_successes,
            ],
            'created_at' => now(),
        ]);

        $this->notifications->toAdmins(
            NotificationCategory::Provider,
            'provider_status',
            "{$provider->name}: {$from->label()} → {$to->label()}",
            $reason,
            ['provider' => $provider->key, 'from' => $from->value, 'to' => $to->value],
            '/admin/providers',
        );
    }

    private function activeProvider(string $key): ?ApiProvider
    {
        $provider = ApiProvider::query()->where('key', $key)->first();

        // Skip rows that are missing or manually disabled.
        if ($provider === null || ! $provider->is_active || $provider->status === ProviderStatus::Disabled) {
            return null;
        }

        return $provider;
    }

    private function threshold(string $name): int
    {
        return max(1, (int) config("tradenews.providers.health.{$name}", 2));
    }
}
