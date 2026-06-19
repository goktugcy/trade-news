<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use Database\Factories\ApiProviderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property ProviderType $type
 * @property array<int, string>|null $markets
 * @property array<int, string>|null $capabilities
 * @property ProviderStatus $status
 * @property bool $is_active
 * @property bool $auto_sync_stocks
 * @property bool $auto_recovery
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property string|null $base_url
 * @property string|null $api_key
 * @property int $priority
 * @property int $refresh_interval_minutes
 * @property int $fetch_limit
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_fetched_at
 * @property int|null $last_latency_ms
 * @property string|null $last_error
 * @property array<string, mixed>|null $meta
 * @property-read Collection<int, AiModel> $aiModels
 */
class ApiProvider extends Model
{
    /** @use HasFactory<ApiProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'name', 'type', 'markets', 'capabilities', 'status', 'is_active',
        'auto_sync_stocks', 'auto_recovery', 'consecutive_failures', 'consecutive_successes', 'base_url', 'api_key',
        'priority', 'refresh_interval_minutes', 'fetch_limit',
        'last_checked_at', 'last_fetched_at', 'last_latency_ms',
        'last_error', 'meta',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'refresh_interval_minutes' => 5,
        'fetch_limit' => 50,
        'auto_sync_stocks' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'markets' => 'array',
            'capabilities' => 'array',
            'status' => ProviderStatus::class,
            'is_active' => 'boolean',
            'auto_sync_stocks' => 'boolean',
            'auto_recovery' => 'boolean',
            'api_key' => 'encrypted',
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'priority' => 'integer',
            'refresh_interval_minutes' => 'integer',
            'fetch_limit' => 'integer',
            'last_checked_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'last_latency_ms' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * @return HasMany<ProviderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProviderEvent::class)->latest('id');
    }

    /**
     * @return HasMany<AiModel, $this>
     */
    public function aiModels(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    public function hasApiKey(): bool
    {
        return trim((string) $this->api_key) !== '';
    }

    public function isDueForFetch(?Carbon $now = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->last_fetched_at === null) {
            return true;
        }

        $now ??= now();

        return $this->last_fetched_at->lte($now->copy()->subMinutes(max(1, $this->refresh_interval_minutes)));
    }

    public function isDueForCapability(string $capability, ?Carbon $now = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $lastActivityAt = $this->lastCapabilityActivityAt($capability);

        if ($lastActivityAt === null) {
            return true;
        }

        $now ??= now();

        return $lastActivityAt->lte($now->copy()->subMinutes(max(1, $this->refresh_interval_minutes)));
    }

    public function markFetched(?Carbon $at = null): void
    {
        $this->forceFill(['last_fetched_at' => $at ?? now()])->save();
    }

    public function markCapabilityFetched(string $capability, ?Carbon $at = null): void
    {
        $at ??= now();
        $meta = $this->meta ?? [];
        $fetchedAt = $meta['last_fetched_at_by_capability'] ?? [];

        if (! is_array($fetchedAt)) {
            $fetchedAt = [];
        }

        $fetchedAt[$capability] = $at->toISOString();
        $meta['last_fetched_at_by_capability'] = $fetchedAt;

        $this->forceFill([
            'last_fetched_at' => $at,
            'meta' => $meta,
        ])->save();
    }

    public function markCapabilityAttempted(string $capability, ?Carbon $at = null): void
    {
        $at ??= now();
        $meta = $this->meta ?? [];
        $attemptedAt = $meta['last_attempted_at_by_capability'] ?? [];

        if (! is_array($attemptedAt)) {
            $attemptedAt = [];
        }

        $attemptedAt[$capability] = $at->toISOString();
        $meta['last_attempted_at_by_capability'] = $attemptedAt;

        $this->forceFill(['meta' => $meta])->save();
    }

    private function lastCapabilityActivityAt(string $capability): ?Carbon
    {
        $timestamps = array_filter([
            $this->capabilityTimestamp('last_fetched_at_by_capability', $capability),
            $this->capabilityTimestamp('last_attempted_at_by_capability', $capability),
        ]);

        if ($timestamps === []) {
            return null;
        }

        return collect($timestamps)
            ->sortByDesc(fn (Carbon $timestamp): int => $timestamp->getTimestamp())
            ->first();
    }

    private function capabilityTimestamp(string $metaKey, string $capability): ?Carbon
    {
        $meta = $this->meta ?? [];
        $timestamps = $meta[$metaKey] ?? [];

        if (! is_array($timestamps) || ! isset($timestamps[$capability]) || ! is_string($timestamps[$capability])) {
            return null;
        }

        try {
            return Carbon::parse($timestamps[$capability]);
        } catch (\Throwable) {
            return null;
        }
    }
}
