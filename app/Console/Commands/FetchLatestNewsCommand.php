<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\ProviderType;
use App\Jobs\FetchMarketNewsJob;
use App\Models\SystemJob;
use App\Services\Providers\ApiProviderRegistry;
use Illuminate\Console\Command;

class FetchLatestNewsCommand extends Command
{
    protected $signature = 'tradenews:fetch-news {--market= : Limit to BIST or NASDAQ}';

    protected $description = 'Dispatch news-fetch jobs (per market) via the configured news provider';

    public function handle(ApiProviderRegistry $providers): int
    {
        SystemJob::track('tradenews:fetch-news', function (SystemJob $job) use ($providers): void {
            $dueProviders = $providers->dueProviderRows(ProviderType::News);

            if ($dueProviders->isEmpty()) {
                $job->update(['meta' => [
                    'dispatched' => 0,
                    'provider_keys' => [],
                    'skipped' => 'no_due_provider',
                ]]);

                return;
            }

            $market = $this->option('market') ? Market::from($this->option('market')) : null;
            $limit = $providers->fetchLimitFor(ProviderType::News);

            // One job per active source provider so every feed contributes.
            foreach ($dueProviders as $provider) {
                FetchMarketNewsJob::dispatch($provider->key, $market, $limit);
            }

            $providers->markFetched($dueProviders);

            $job->update(['meta' => [
                'dispatched' => $dueProviders->count(),
                'market' => $market?->value,
                'limit' => $limit,
                'provider_keys' => $dueProviders->pluck('key')->values()->all(),
            ]]);
        });

        $this->info('Dispatched news-fetch job(s).');

        return self::SUCCESS;
    }
}
