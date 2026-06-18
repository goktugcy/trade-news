<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\Market;
use App\Enums\NotificationCategory;
use App\Models\Stock;
use App\Models\SyncRun;
use App\Services\Notification\NotificationCenter;
use App\Services\Providers\ProviderHealthService;
use Throwable;

/**
 * Synchronizes the NASDAQ universe + company profiles from Financial Modeling
 * Prep. Records each run in sync_runs, drives the "fmp" provider health state
 * machine, and notifies admins on failure / recovery.
 */
class NasdaqSyncService
{
    public const PROVIDER_KEY = 'fmp';

    public function __construct(
        private readonly FmpClient $fmp,
        private readonly ProviderHealthService $health,
        private readonly NotificationCenter $notifications,
    ) {}

    public function syncList(): SyncRun
    {
        $run = $this->startRun('nasdaq_list');

        try {
            $rows = $this->fmp->stockList();
            $existing = Stock::query()->where('market', Market::NASDAQ->value)
                ->pluck('symbol')->map(fn (string $s) => mb_strtoupper($s))->flip();

            $created = 0;
            $updated = 0;
            $processed = 0;

            foreach ($rows as $row) {
                $symbol = mb_strtoupper(trim((string) ($row['symbol'] ?? '')));

                if ($symbol === '') {
                    continue;
                }

                $name = (string) ($row['name'] ?? $symbol);

                Stock::query()->updateOrCreate(
                    ['market' => Market::NASDAQ->value, 'symbol' => $symbol],
                    ['name' => $name, 'exchange' => 'NASDAQ', 'currency' => 'USD', 'is_active' => true, 'aliases' => [$symbol, $name]],
                );

                $existing->has($symbol) ? $updated++ : $created++;
                $processed++;
            }

            return $this->finish($run, $processed, $created, $updated);
        } catch (Throwable $e) {
            if ($this->isUnavailableFmpListEndpoint($e)) {
                return $this->skipRun($run, 'fmp_list_endpoint_unavailable', $e);
            }

            return $this->failRun($run, $e);
        }
    }

    public function syncProfiles(int $limit): SyncRun
    {
        $run = $this->startRun('company_profiles');

        try {
            $ttlDays = (int) config('tradenews.sync.fmp.profile_ttl_days', 30);

            $stocks = Stock::query()
                ->where('market', Market::NASDAQ->value)
                ->where('is_active', true)
                ->where(function ($q) use ($ttlDays): void {
                    $q->whereNull('profile_synced_at')
                        ->orWhere('profile_synced_at', '<', now()->subDays($ttlDays));
                })
                ->orderByRaw('profile_synced_at asc nulls first')
                ->limit($limit)
                ->get();

            $processed = 0;
            $updated = 0;

            foreach ($stocks as $stock) {
                $profile = $this->fmp->profile($stock->symbol);
                $processed++;

                if ($profile === null) {
                    $stock->forceFill(['profile_synced_at' => now()])->save();

                    continue;
                }

                $stock->forceFill([
                    'sector' => $profile['sector'] ?? $stock->sector,
                    'industry' => $profile['industry'] ?? null,
                    'market_cap' => isset($profile['mktCap']) ? (float) $profile['mktCap'] : null,
                    'website' => $profile['website'] ?? null,
                    'description' => $profile['description'] ?? null,
                    'logo_url' => $profile['image'] ?? $stock->logo_url,
                    'company_profile' => $profile,
                    'profile_synced_at' => now(),
                ])->save();

                $updated++;
            }

            return $this->finish($run, $processed, 0, $updated);
        } catch (Throwable $e) {
            return $this->failRun($run, $e);
        }
    }

    private function startRun(string $type): SyncRun
    {
        return SyncRun::create([
            'type' => $type,
            'provider_key' => self::PROVIDER_KEY,
            'status' => SyncRun::STATUS_RUNNING,
            'started_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function finish(SyncRun $run, int $processed, int $created, int $updated): SyncRun
    {
        $previous = $this->previousRun($run);

        $run->update([
            'status' => SyncRun::STATUS_SUCCESS,
            'processed' => $processed,
            'created_count' => $created,
            'updated_count' => $updated,
            'finished_at' => now(),
        ]);

        $this->health->recordSuccess(self::PROVIDER_KEY, 'sync:'.$run->type);

        // Notify admins that a previously-broken sync recovered.
        if ($previous?->status === SyncRun::STATUS_FAILED) {
            $this->notifications->toAdmins(
                NotificationCategory::Sync,
                'sync_recovered',
                ucfirst(str_replace('_', ' ', $run->type)).' sync recovered',
                "Processed {$processed} ({$created} new, {$updated} updated).",
                ['type' => $run->type],
                '/admin/sync-logs',
            );
        }

        return $run;
    }

    private function skipRun(SyncRun $run, string $reason, Throwable $e): SyncRun
    {
        $run->update([
            'status' => SyncRun::STATUS_SUCCESS,
            'processed' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'finished_at' => now(),
            'error' => null,
            'meta' => [
                'skipped' => $reason,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ],
        ]);

        return $run;
    }

    private function failRun(SyncRun $run, Throwable $e): SyncRun
    {
        $run->update([
            'status' => SyncRun::STATUS_FAILED,
            'finished_at' => now(),
            'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);

        $this->health->recordFailure(self::PROVIDER_KEY, $e->getMessage());

        $this->notifications->toAdmins(
            NotificationCategory::Sync,
            'sync_failed',
            ucfirst(str_replace('_', ' ', $run->type)).' sync failed',
            mb_substr($e->getMessage(), 0, 300),
            ['type' => $run->type],
            '/admin/sync-logs',
        );

        return $run;
    }

    private function previousRun(SyncRun $run): ?SyncRun
    {
        return SyncRun::query()
            ->where('type', $run->type)
            ->where('id', '<', $run->id)
            ->latest('id')
            ->first();
    }

    private function isUnavailableFmpListEndpoint(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Legacy Endpoint')
            || str_contains($message, 'Restricted Endpoint')
            || str_contains($message, 'current subscription');
    }
}
