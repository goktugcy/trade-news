<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiProvider;
use App\Models\SystemJob;
use App\Services\Providers\ProviderHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckProviderHealthCommand extends Command
{
    protected $signature = 'tradenews:check-providers';

    protected $description = 'Probe each active provider and drive its status state machine';

    public function handle(ProviderHealthService $health): int
    {
        SystemJob::track('tradenews:check-providers', function () use ($health): void {
            ApiProvider::query()->where('is_active', true)->get()->each(function (ApiProvider $provider) use ($health): void {
                [$ok, $latency, $error] = $this->probe($provider);

                $provider->forceFill([
                    'last_checked_at' => now(),
                    'last_latency_ms' => $latency,
                ])->save();

                // Transitions, event logging and admin notifications happen here.
                $ok
                    ? $health->recordSuccess($provider->key, 'health_check')
                    : $health->recordFailure($provider->key, $error ?? 'unreachable');

                $this->line("{$provider->key}: ".($ok ? 'ok' : 'fail').($latency !== null ? " ({$latency}ms)" : ''));
            });
        });

        return self::SUCCESS;
    }

    /**
     * Liveness probe: a reachable host (2xx or 4xx) counts as success; only 5xx
     * or a connection failure counts as a failed request.
     *
     * @return array{0: bool, 1: int|null, 2: string|null}
     */
    private function probe(ApiProvider $provider): array
    {
        // Local / non-HTTP providers (synthetic, rss, …) are always reachable.
        if (str_starts_with($provider->key, 'synthetic') || ! $provider->base_url) {
            return [true, 0, null];
        }

        $start = microtime(true);

        try {
            $response = Http::timeout(8)->get($provider->base_url);
            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($response->serverError()) {
                return [false, $latency, "HTTP {$response->status()}"];
            }

            return [true, $latency, null];
        } catch (\Throwable $e) {
            return [false, null, mb_substr($e->getMessage(), 0, 500)];
        }
    }
}
