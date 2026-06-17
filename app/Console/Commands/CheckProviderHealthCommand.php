<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProviderStatus;
use App\Models\ApiProvider;
use App\Models\SystemJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckProviderHealthCommand extends Command
{
    protected $signature = 'tradenews:check-providers';

    protected $description = 'Ping each API provider, measure latency, and record status';

    public function handle(): int
    {
        SystemJob::track('tradenews:check-providers', function (): void {
            ApiProvider::query()->where('is_active', true)->get()->each(function (ApiProvider $provider): void {
                [$status, $latency, $error] = $this->probe($provider);

                $provider->update([
                    'status' => $status,
                    'last_checked_at' => now(),
                    'last_latency_ms' => $latency,
                    'last_error' => $error,
                ]);

                $this->line("{$provider->key}: {$status->value}".($latency ? " ({$latency}ms)" : ''));
            });
        });

        return self::SUCCESS;
    }

    /**
     * @return array{0: ProviderStatus, 1: int|null, 2: string|null}
     */
    private function probe(ApiProvider $provider): array
    {
        // The synthetic provider is local — always healthy.
        if ($provider->key === 'synthetic') {
            return [ProviderStatus::Operational, 0, null];
        }

        if (! $provider->base_url) {
            return [ProviderStatus::Unknown, null, 'No base URL configured.'];
        }

        $start = microtime(true);

        try {
            $response = Http::timeout(8)->get($provider->base_url);
            $latency = (int) round((microtime(true) - $start) * 1000);

            $status = match (true) {
                $response->successful() => ProviderStatus::Operational,
                $response->serverError() => ProviderStatus::Down,
                default => ProviderStatus::Degraded, // 4xx still means the host responds
            };

            return [$status, $latency, $response->failed() ? "HTTP {$response->status()}" : null];
        } catch (\Throwable $e) {
            return [ProviderStatus::Down, null, mb_substr($e->getMessage(), 0, 500)];
        }
    }
}
