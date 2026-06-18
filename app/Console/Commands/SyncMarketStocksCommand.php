<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\ProviderType;
use App\Models\ApiProvider;
use App\Models\SyncRun;
use App\Models\SystemJob;
use App\Services\Providers\ApiProviderRegistry;
use App\Services\Sync\NasdaqSyncService;
use App\Services\Sync\RapidApiBist100SyncService;
use Illuminate\Console\Command;

class SyncMarketStocksCommand extends Command
{
    protected $signature = 'tradenews:sync-market-stocks
        {--force : Ignore provider capability refresh intervals}';

    protected $description = 'Run provider-driven market universe auto-sync jobs';

    public function handle(
        ApiProviderRegistry $registry,
        NasdaqSyncService $nasdaq,
        RapidApiBist100SyncService $bist100,
    ): int {
        return SystemJob::track('tradenews:sync-market-stocks', function (SystemJob $job) use ($registry, $nasdaq, $bist100): int {
            $providers = ApiProvider::query()
                ->where('type', ProviderType::MarketData->value)
                ->where('is_active', true)
                ->where('auto_sync_stocks', true)
                ->orderBy('priority')
                ->orderBy('id')
                ->get();

            if ($providers->isEmpty()) {
                $this->markJob($job, [
                    'provider_keys' => [],
                    'skipped' => 'no_auto_sync_providers',
                ]);

                return self::SUCCESS;
            }

            $meta = [
                'provider_keys' => [],
                'sync_run_ids' => [],
                'skipped' => [],
            ];
            $hasFailure = false;

            foreach ($providers as $provider) {
                if ($provider->key === NasdaqSyncService::PROVIDER_KEY) {
                    $hasFailure = $this->runFmp($provider, $registry, $nasdaq, $meta) || $hasFailure;

                    continue;
                }

                if ($this->isBist100Provider($provider)) {
                    $hasFailure = $this->runBist100($provider, $registry, $bist100, $meta) || $hasFailure;

                    continue;
                }

                $this->skip($meta, $provider, 'unsupported_auto_sync_provider');
            }

            $this->markJob($job, $meta);

            return $hasFailure ? self::FAILURE : self::SUCCESS;
        });
    }

    /**
     * @param  array{provider_keys: array<int, string>, sync_run_ids: array<int, int>, skipped: array<int, array<string, string>>}  $meta
     */
    private function runFmp(
        ApiProvider $provider,
        ApiProviderRegistry $registry,
        NasdaqSyncService $nasdaq,
        array &$meta,
    ): bool {
        if (! $this->providerTargetsMarket($provider, Market::NASDAQ)) {
            $this->skip($meta, $provider, 'market_not_selected');

            return false;
        }

        $hasFailure = false;

        if ($registry->providerHasAnyCapability($provider, ['list'])) {
            $hasFailure = $this->runFmpList($provider, $nasdaq, $meta) || $hasFailure;
        }

        if ($registry->providerHasAnyCapability($provider, ['profiles'])) {
            $hasFailure = $this->runFmpProfiles($provider, $nasdaq, $meta) || $hasFailure;
        }

        if (! $registry->providerHasAnyCapability($provider, ['list', 'profiles'])) {
            $this->skip($meta, $provider, 'missing_auto_sync_capability');
        }

        return $hasFailure;
    }

    /**
     * @param  array{provider_keys: array<int, string>, sync_run_ids: array<int, int>, skipped: array<int, array<string, string>>}  $meta
     */
    private function runFmpList(ApiProvider $provider, NasdaqSyncService $nasdaq, array &$meta): bool
    {
        $capability = 'list';

        if (! $this->isDue($provider, $capability)) {
            $this->skip($meta, $provider, 'not_due', $capability);

            return false;
        }

        $provider->markCapabilityAttempted($capability);

        if (! $this->providerHasApiKey($provider)) {
            $this->skip($meta, $provider, 'missing_api_key', $capability);

            return false;
        }

        $run = $nasdaq->syncList();
        $this->recordRun($meta, $provider, $run);

        if ($run->status === SyncRun::STATUS_SUCCESS && ($run->meta['skipped'] ?? null) !== 'fmp_list_endpoint_unavailable') {
            $provider->markCapabilityFetched($capability);
        }

        return $run->status === SyncRun::STATUS_FAILED;
    }

    /**
     * @param  array{provider_keys: array<int, string>, sync_run_ids: array<int, int>, skipped: array<int, array<string, string>>}  $meta
     */
    private function runFmpProfiles(ApiProvider $provider, NasdaqSyncService $nasdaq, array &$meta): bool
    {
        $capability = 'profiles';

        if (! $this->isDue($provider, $capability)) {
            $this->skip($meta, $provider, 'not_due', $capability);

            return false;
        }

        $provider->markCapabilityAttempted($capability);

        if (! $this->providerHasApiKey($provider)) {
            $this->skip($meta, $provider, 'missing_api_key', $capability);

            return false;
        }

        $run = $nasdaq->syncProfiles(max(1, $provider->fetch_limit));
        $this->recordRun($meta, $provider, $run);

        if ($run->status === SyncRun::STATUS_SUCCESS) {
            $provider->markCapabilityFetched($capability);
        }

        return $run->status === SyncRun::STATUS_FAILED;
    }

    /**
     * @param  array{provider_keys: array<int, string>, sync_run_ids: array<int, int>, skipped: array<int, array<string, string>>}  $meta
     */
    private function runBist100(
        ApiProvider $provider,
        ApiProviderRegistry $registry,
        RapidApiBist100SyncService $bist100,
        array &$meta,
    ): bool {
        if (! $this->providerTargetsMarket($provider, Market::BIST)) {
            $this->skip($meta, $provider, 'market_not_selected');

            return false;
        }

        if (! $registry->providerHasAnyCapability($provider, ['list', 'quotes'])) {
            $this->skip($meta, $provider, 'missing_auto_sync_capability');

            return false;
        }

        $capabilities = $this->providerCapabilities($provider, ['list', 'quotes']);
        $dueCapabilities = $this->dueCapabilities($provider, $capabilities);

        if ($dueCapabilities === []) {
            $this->skip($meta, $provider, 'not_due', implode(',', $capabilities));

            return false;
        }

        foreach ($dueCapabilities as $capability) {
            $provider->markCapabilityAttempted($capability);
        }

        if (! $this->providerHasApiKey($provider)) {
            $this->skip($meta, $provider, 'missing_api_key', implode(',', $dueCapabilities));

            return false;
        }

        $run = $bist100->sync($provider);
        $this->recordRun($meta, $provider, $run);

        if ($run->status === SyncRun::STATUS_SUCCESS) {
            foreach ($capabilities as $capability) {
                $provider->markCapabilityFetched($capability);
            }
        }

        return $run->status === SyncRun::STATUS_FAILED;
    }

    private function providerTargetsMarket(ApiProvider $provider, Market $market): bool
    {
        $markets = $provider->markets ?? [];

        if ($markets === []) {
            return true;
        }

        return in_array($market->value, $markets, true);
    }

    private function isBist100Provider(ApiProvider $provider): bool
    {
        if (in_array($provider->key, [RapidApiBist100SyncService::PROVIDER_KEY, 'rapid', 'rapidapi'], true)) {
            return true;
        }

        return str_contains(
            mb_strtolower((string) $provider->base_url),
            'bist100-stock-data-15-minutes-late-live.p.rapidapi.com',
        );
    }

    /**
     * @param  array<int, string>  $candidates
     * @return array<int, string>
     */
    private function providerCapabilities(ApiProvider $provider, array $candidates): array
    {
        $configured = $provider->capabilities ?? [];

        if ($configured === []) {
            return $candidates;
        }

        return array_values(array_intersect($candidates, array_map(
            fn (mixed $capability): string => (string) $capability,
            $configured,
        )));
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<int, string>
     */
    private function dueCapabilities(ApiProvider $provider, array $capabilities): array
    {
        if ((bool) $this->option('force')) {
            return $capabilities;
        }

        return array_values(array_filter(
            $capabilities,
            fn (string $capability): bool => $provider->isDueForCapability($capability),
        ));
    }

    private function isDue(ApiProvider $provider, string $capability): bool
    {
        return (bool) $this->option('force') || $provider->isDueForCapability($capability);
    }

    private function providerHasApiKey(ApiProvider $provider): bool
    {
        return trim((string) $provider->api_key) !== '';
    }

    /**
     * @param  array{provider_keys: array<int, string>, sync_run_ids: array<int, int>, skipped: array<int, array<string, string>>}  $meta
     */
    private function recordRun(array &$meta, ApiProvider $provider, SyncRun $run): void
    {
        if (! in_array($provider->key, $meta['provider_keys'], true)) {
            $meta['provider_keys'][] = $provider->key;
        }

        $meta['sync_run_ids'][] = $run->id;
    }

    /**
     * @param  array{provider_keys: array<int, string>, sync_run_ids: array<int, int>, skipped: array<int, array<string, string>>}  $meta
     */
    private function skip(array &$meta, ApiProvider $provider, string $reason, ?string $capability = null): void
    {
        $entry = [
            'provider_key' => $provider->key,
            'reason' => $reason,
        ];

        if ($capability !== null) {
            $entry['capability'] = $capability;
        }

        $meta['skipped'][] = $entry;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function markJob(SystemJob $job, array $meta): void
    {
        $job->update(['meta' => $meta]);
    }
}
