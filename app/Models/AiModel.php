<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Enums\ProviderStatus;
use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $api_provider_id
 * @property string $name
 * @property string $model
 * @property AiTask|null $task
 * @property AiRuntime|null $runtime
 * @property string|null $endpoint_url
 * @property bool $is_active
 * @property ProviderStatus $status
 * @property Carbon|null $last_checked_at
 * @property int|null $last_latency_ms
 * @property string|null $last_error
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property int $max_output_tokens
 * @property float|null $temperature
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ApiProvider $provider
 */
class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use HasFactory;

    protected $fillable = [
        'api_provider_id',
        'name',
        'model',
        'task',
        'runtime',
        'endpoint_url',
        'is_active',
        'status',
        'last_checked_at',
        'last_latency_ms',
        'last_error',
        'consecutive_failures',
        'consecutive_successes',
        'max_output_tokens',
        'temperature',
        'meta',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'status' => 'unknown',
        'max_output_tokens' => 160,
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'runtime' => AiRuntime::class,
            'is_active' => 'boolean',
            'status' => ProviderStatus::class,
            'last_checked_at' => 'datetime',
            'last_latency_ms' => 'integer',
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'max_output_tokens' => 'integer',
            'temperature' => 'float',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ApiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ApiProvider::class, 'api_provider_id');
    }

    /**
     * A model is usable when it (and its provider) are active, the provider has
     * an API key, and the model has not been marked down/disabled by health checks.
     */
    public function isHealthy(): bool
    {
        $provider = $this->provider;

        return $this->is_active
            && $provider !== null
            && $provider->is_active
            && $provider->hasApiKey()
            && ! in_array($this->status, [ProviderStatus::Down, ProviderStatus::Disabled], true);
    }

    /**
     * The endpoint the client should call — model-level override, else the
     * provider base URL.
     */
    public function resolvedEndpoint(): ?string
    {
        $endpoint = trim((string) $this->endpoint_url);

        if ($endpoint !== '') {
            return $endpoint;
        }

        $base = trim((string) $this->provider?->base_url);

        return $base !== '' ? $base : null;
    }
}
