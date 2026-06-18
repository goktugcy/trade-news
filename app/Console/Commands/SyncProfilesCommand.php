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

class SyncProfilesCommand extends Command
{
    private const CAPABILITY = 'profiles';

    protected $signature = 'tradenews:sync-profiles
        {--limit= : Max profiles to fetch this run}
        {--force : Ignore the FMP provider refresh interval and sync now}';

    protected $description = 'Sync NASDAQ company profiles/metadata from Financial Modeling Prep';

    public function handle(FmpClient $fmp, NasdaqSyncService $sync, ApiProviderRegistry $providers): int
    {
        return SystemJob::track('tradenews:sync-profiles', function (SystemJob $job) use ($fmp, $sync, $providers): int {
            $provider = $this->fmpProvider();

            if ($this->shouldSkip($job, $providers, $provider)) {
                return self::SUCCESS;
            }

            if (! $fmp->isConfigured()) {
                $this->warn('FMP provider API key is not configured — skipping profile sync.');
                $provider?->markCapabilityAttempted(self::CAPABILITY);
                $job->update(['meta' => [
                    'provider_keys' => [],
                    'capability' => self::CAPABILITY,
                    'skipped' => 'missing_api_key',
                ]]);

                return self::SUCCESS;
            }

            $limit = $this->limit($provider);
            $provider?->markCapabilityAttempted(self::CAPABILITY);
            $run = $sync->syncProfiles($limit);

            if ($provider !== null && $run->status === SyncRun::STATUS_SUCCESS) {
                $provider->markCapabilityFetched(self::CAPABILITY);
            }

            $job->update(['meta' => [
                'provider_keys' => [NasdaqSyncService::PROVIDER_KEY],
                'capability' => self::CAPABILITY,
                'limit' => $limit,
                'sync_run_id' => $run->id,
                'sync_status' => $run->status,
            ]]);

            $this->info("Profile sync: {$run->status} ({$run->updated_count}/{$run->processed} updated).");

            return $run->status === SyncRun::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
        });
    }

    private function shouldSkip(SystemJob $job, ApiProviderRegistry $providers, ?ApiProvider $provider): bool
    {
        if ($provider === null) {
            return false;
        }

        if (! $provider->is_active) {
            $this->info('FMP provider is disabled; skipping profile sync.');
            $this->markSkipped($job, 'provider_disabled');

            return true;
        }

        if (! $providers->providerHasAnyCapability($provider, [self::CAPABILITY])) {
            $this->info('FMP provider does not have the profiles capability; skipping profile sync.');
            $this->markSkipped($job, 'missing_capability');

            return true;
        }

        if (! $this->option('force') && ! $provider->isDueForCapability(self::CAPABILITY)) {
            $this->info('FMP profile sync is not due.');
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

    private function limit(?ApiProvider $provider): int
    {
        $limit = $this->option('limit');

        if ($limit !== null && $limit !== '') {
            return max(1, (int) $limit);
        }

        if ($provider !== null) {
            return max(1, $provider->fetch_limit);
        }

        return max(1, (int) config('tradenews.sync.fmp.profile_batch', 50));
    }

    private function fmpProvider(): ?ApiProvider
    {
        return ApiProvider::query()
            ->where('key', NasdaqSyncService::PROVIDER_KEY)
            ->where('type', ProviderType::MarketData->value)
            ->first();
    }
}
