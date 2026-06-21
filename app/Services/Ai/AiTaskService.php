<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Enums\ProviderStatus;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\AiTaskSetting;

/**
 * Resolves the active, healthy AI model/client for a given task, gated by the
 * global AI master switch and the per-task setting. Also records model health.
 */
class AiTaskService
{
    public function __construct(private readonly AiProviderClientFactory $clients) {}

    public function globallyEnabled(): bool
    {
        return AiSetting::current()->enabled;
    }

    public function isEnabled(AiTask $task): bool
    {
        if (! $this->globallyEnabled()) {
            return false;
        }

        return (bool) AiTaskSetting::query()->where('task', $task->value)->value('enabled');
    }

    /**
     * The healthy model configured for a task, or null when the task is
     * disabled / unconfigured / unhealthy (caller should use its fallback).
     */
    public function modelFor(AiTask $task): ?AiModel
    {
        $model = $this->resolveModel($task);

        return $model !== null && $model->isHealthy() ? $model : null;
    }

    /**
     * Like modelFor() but only requires the model to be *configured* (active +
     * provider active + key), ignoring the down/disabled health flag. For
     * explicit user actions (on-demand translate) that should be allowed to
     * attempt — and thereby recover — a model previously marked down.
     */
    public function configuredModelFor(AiTask $task): ?AiModel
    {
        $model = $this->resolveModel($task);

        return $model !== null && $model->isConfigured() ? $model : null;
    }

    private function resolveModel(AiTask $task): ?AiModel
    {
        if (! $this->isEnabled($task)) {
            return null;
        }

        $setting = AiTaskSetting::query()
            ->with('activeModel.provider')
            ->where('task', $task->value)
            ->first();

        $model = $setting?->activeModel;

        if ($model === null) {
            // Fall back to any active model tagged with this task.
            $model = AiModel::query()
                ->with('provider')
                ->where('task', $task->value)
                ->where('is_active', true)
                ->first();
        }

        return $model;
    }

    public function clientFor(AiModel $model): ?AiProviderClientInterface
    {
        return $this->clients->make($model->provider);
    }

    public function huggingFaceFor(AiModel $model): ?HuggingFaceEndpointClient
    {
        $client = $this->clients->make($model->provider);

        return $client instanceof HuggingFaceEndpointClient ? $client : null;
    }

    /**
     * Runtime-aware connection test for a model. Chat models do a tiny
     * completion; HF pipeline models exercise their pipeline.
     */
    public function test(AiModel $model): AiCompletionResult
    {
        $client = $this->clients->make($model->provider);

        if ($client === null) {
            return new AiCompletionResult(false, error: 'Unsupported AI provider.');
        }

        if ($client instanceof HuggingFaceEndpointClient && $model->runtime !== null && ! $model->runtime->isChat()) {
            return match ($model->runtime) {
                AiRuntime::HfTextClassification => $client->classify($model, 'The company reported strong quarterly earnings.'),
                AiRuntime::HfTokenClassification => $client->tokenClassify($model, 'Apple Inc. reported earnings.'),
                AiRuntime::HfFeatureExtraction => $client->featureExtract($model, 'connection test'),
                AiRuntime::HfRanking => $client->rank($model, 'query', ['document one', 'document two']),
                default => $client->complete($model, 'Reply with exactly OK.', 'Return only OK.'),
            };
        }

        return $client->complete(
            $model,
            'Reply with exactly OK.',
            'You are a provider health check. Return only OK and no other text.',
        );
    }

    public function recordSuccess(AiModel $model, ?int $latencyMs): void
    {
        $model->forceFill([
            'status' => ProviderStatus::Operational,
            'last_checked_at' => now(),
            'last_latency_ms' => $latencyMs,
            'last_error' => null,
            'consecutive_failures' => 0,
            'consecutive_successes' => $model->consecutive_successes + 1,
        ])->save();
    }

    public function recordFailure(AiModel $model, ?string $error, ?int $latencyMs = null): void
    {
        $failures = $model->consecutive_failures + 1;

        $model->forceFill([
            'status' => $failures >= 3 ? ProviderStatus::Down : ProviderStatus::Degraded,
            'last_checked_at' => now(),
            'last_latency_ms' => $latencyMs,
            'last_error' => $error !== null ? mb_substr($error, 0, 500) : 'AI request failed.',
            'consecutive_failures' => $failures,
            'consecutive_successes' => 0,
        ])->save();
    }
}
