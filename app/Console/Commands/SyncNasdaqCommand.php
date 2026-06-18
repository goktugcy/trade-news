<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProviderType;
use App\Models\ApiProvider;
use App\Models\SyncRun;
use App\Models\SystemJob;
use App\Services\Providers\ApiProviderRegistry;
use App\Services\Sync\FmpClient;
use App\Services\Sync\NasdaqSyncService;
use Illuminate\Console\Command;

class SyncNasdaqCommand extends Command
{
    private const CAPABILITY = 'list';

    protected $signature = 'tradenews:sync-nasdaq
        {--force : Ignore the FMP provider refresh interval and sync now}';

    protected $description = 'Sync the NASDAQ universe from Financial Modeling Prep (falls back to Finnhub when no FMP provider key)';

    public function handle(FmpClient $fmp, NasdaqSyncService $sync, ApiProviderRegistry $providers): int
    {
        return SystemJob::track('tradenews:sync-nasdaq', function (SystemJob $job) use ($fmp, $sync, $providers): int {
            $provider = $this->fmpProvider();

            if ($this->shouldSkip($job, $providers, $provider)) {
                return self::SUCCESS;
            }

            if (! $fmp->isConfigured()) {
                $this->warn('FMP provider API key is not configured — falling back to tradenews:sync-nasdaq-stocks (Finnhub).');
                $provider?->markCapabilityAttempted(self::CAPABILITY);

                $exitCode = $this->call('tradenews:sync-nasdaq-stocks');

                $job->update(['meta' => [
                    'provider_keys' => [],
                    'capability' => self::CAPABILITY,
                    'fallback_provider' => 'finnhub',
                    'exit_code' => $exitCode,
                ]]);

                return $exitCode;
            }

            $provider?->markCapabilityAttempted(self::CAPABILITY);
            $run = $sync->syncList();

            if ($provider !== null && $run->status === SyncRun::STATUS_SUCCESS && ! $this->shouldFallbackFromRun($run)) {
                $provider->markCapabilityFetched(self::CAPABILITY);
            }

            if ($this->shouldFallbackFromRun($run)) {
                $this->warn('FMP NASDAQ list endpoint is unavailable for this subscription; falling back to Finnhub.');

                $exitCode = $this->call('tradenews:sync-nasdaq-stocks');

                $job->update(['meta' => [
                    'provider_keys' => ['finnhub'],
                    'capability' => self::CAPABILITY,
                    'fallback_provider' => 'finnhub',
                    'fmp_sync_run_id' => $run->id,
                    'fmp_skipped' => $run->meta['skipped'] ?? null,
                    'exit_code' => $exitCode,
                ]]);

                return $exitCode;
            }

            $job->update(['meta' => [
                'provider_keys' => [NasdaqSyncService::PROVIDER_KEY],
                'capability' => self::CAPABILITY,
                'sync_run_id' => $run->id,
                'sync_status' => $run->status,
            ]]);

            $this->info("NASDAQ list sync: {$run->status} ({$run->created_count} new, {$run->updated_count} updated).");

            return $run->status === SyncRun::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
        });
    }

    private function shouldSkip(SystemJob $job, ApiProviderRegistry $providers, ?ApiProvider $provider): bool
    {
        if ($provider === null) {
            return false;
        }

        if (! $provider->is_active) {
            $this->info('FMP provider is disabled; skipping NASDAQ list sync.');
            $this->markSkipped($job, 'provider_disabled');

            return true;
        }

        if (! $providers->providerHasAnyCapability($provider, [self::CAPABILITY])) {
            $this->info('FMP provider does not have the list capability; skipping NASDAQ list sync.');
            $this->markSkipped($job, 'missing_capability');

            return true;
        }

        if (! $this->option('force') && ! $provider->isDueForCapability(self::CAPABILITY)) {
            $this->info('FMP NASDAQ list sync is not due.');
            $this->markSkipped($job, 'not_due');

            return true;
        }

        return false;
    }

    private function markSkipped(SystemJob $job, string $reason): void
    {
        $job->update(['meta' => [
            'provider_keys' => [NasdaqSyncService::PROVIDER_KEY],
            'capability' => self::CAPABILITY,
            'skipped' => $reason,
        ]]);
    }

    private function fmpProvider(): ?ApiProvider
    {
        return ApiProvider::query()
            ->where('key', NasdaqSyncService::PROVIDER_KEY)
            ->where('type', ProviderType::MarketData->value)
            ->first();
    }

    private function shouldFallbackFromRun(SyncRun $run): bool
    {
        return ($run->meta['skipped'] ?? null) === 'fmp_list_endpoint_unavailable';
    }
}
