<?php

declare(strict_types=1);

namespace App\Services\MarketData;

use App\Models\SyncRun;
use Carbon\CarbonImmutable;

/**
 * Records historical-import outcomes as SyncRun rows so admins can see, on the
 * /admin/sync-logs page, exactly which stock was imported (and how many candles)
 * or whether a background fetch came back empty (e.g. Stooq's bot-check block).
 */
final class ImportSyncLogger
{
    public const TYPE_STOOQ_HISTORY = 'stooq_history';

    public const TYPE_MANUAL_IMPORT = 'manual_import';

    public const TYPE_BULK_IMPORT = 'bulk_import';

    /**
     * Log a finished import from its summary array.
     *
     * @param  array{processed?: int, created?: int, updated?: int, skipped?: int, stocks_created?: int, errors?: array<int, string>}  $summary
     * @param  array<string, mixed>  $meta
     */
    public static function fromSummary(string $type, ?string $providerKey, array $summary, array $meta = []): SyncRun
    {
        $errors = $summary['errors'] ?? [];
        $now = CarbonImmutable::now();

        return SyncRun::create([
            'type' => $type,
            'provider_key' => $providerKey,
            'status' => $errors === [] ? SyncRun::STATUS_SUCCESS : SyncRun::STATUS_FAILED,
            'processed' => (int) ($summary['processed'] ?? 0),
            'created_count' => (int) ($summary['created'] ?? 0),
            'updated_count' => (int) ($summary['updated'] ?? 0),
            'started_at' => $now,
            'finished_at' => $now,
            'error' => $errors === [] ? null : mb_substr(implode(' | ', $errors), 0, 1000),
            'meta' => array_merge([
                'skipped' => (int) ($summary['skipped'] ?? 0),
                'stocks_created' => (int) ($summary['stocks_created'] ?? 0),
            ], $meta),
            'created_at' => $now,
        ]);
    }

    /**
     * Log a run that imported nothing (no data / source blocked), as a success
     * with an explanatory note rather than an error.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function empty(string $type, ?string $providerKey, string $note, array $meta = []): SyncRun
    {
        $now = CarbonImmutable::now();

        return SyncRun::create([
            'type' => $type,
            'provider_key' => $providerKey,
            'status' => SyncRun::STATUS_SUCCESS,
            'processed' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'started_at' => $now,
            'finished_at' => $now,
            'error' => null,
            'meta' => array_merge(['note' => $note], $meta),
            'created_at' => $now,
        ]);
    }
}
