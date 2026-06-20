<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiModelRequest;
use App\Http\Requests\Admin\StoreAiProviderRequest;
use App\Http\Requests\Admin\UpdateAiProviderRequest;
use App\Http\Requests\Admin\UpdateAiSettingsRequest;
use App\Http\Requests\Admin\UpdateAiTaskRequest;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\AiTaskSetting;
use App\Models\ApiProvider;
use App\Services\Ai\AiCompletionResult;
use App\Services\Ai\AiProviderClientFactory;
use App\Services\Ai\AiTaskService;
use App\Services\Providers\ProviderHealthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    public function index(): Response
    {
        $setting = AiSetting::current()->load('activeModel.provider');
        $providers = ApiProvider::query()
            ->where('type', ProviderType::Ai->value)
            ->with(['aiModels' => fn ($query) => $query->orderBy('name')])
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        $models = $providers->flatMap(fn (ApiProvider $provider) => $provider->aiModels);

        return Inertia::render('admin/AiSettings', [
            'settings' => [
                'enabled' => $setting->enabled,
                'active_ai_model_id' => $setting->active_ai_model_id,
            ],
            'status' => $this->statusPayload($setting),
            'providers' => $providers->map(fn (ApiProvider $provider): array => $this->providerPayload($provider, $setting->active_ai_model_id))->values(),
            'providerOptions' => AiProviderClientFactory::providerOptions(),
            'tasks' => $this->taskPayloads($models),
            'taskOptions' => AiTask::options(),
            'runtimeOptions' => AiRuntime::options(),
        ]);
    }

    public function updateTask(UpdateAiTaskRequest $request, string $task): RedirectResponse
    {
        $taskEnum = AiTask::tryFrom($task);
        abort_if($taskEnum === null, 404);

        $validated = $request->validated();

        if (($validated['active_ai_model_id'] ?? null) !== null) {
            AiModel::query()
                ->whereKey($validated['active_ai_model_id'])
                ->whereHas('provider', fn ($query) => $query->where('type', ProviderType::Ai->value))
                ->firstOrFail();
        }

        AiTaskSetting::query()->updateOrCreate(
            ['task' => $taskEnum->value],
            [
                'enabled' => $validated['enabled'] ?? false,
                'active_ai_model_id' => $validated['active_ai_model_id'] ?? null,
                'fallback_behavior' => $validated['fallback_behavior'] ?? null,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI task updated.']);

        return back();
    }

    public function testTask(string $task, AiTaskService $tasks, AiProviderClientFactory $clients, ProviderHealthService $health): RedirectResponse
    {
        $taskEnum = AiTask::tryFrom($task);
        abort_if($taskEnum === null, 404);

        $setting = AiTaskSetting::query()->with('activeModel.provider')->where('task', $taskEnum->value)->first();
        $model = $setting?->activeModel;

        if ($model === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Select an active model for this task first.']);

            return back();
        }

        return $this->runModelTest($model, $tasks, $clients, $health);
    }

    public function updateSettings(UpdateAiSettingsRequest $request): RedirectResponse
    {
        $setting = AiSetting::current();
        $validated = $request->validated();

        if ($validated['active_ai_model_id'] !== null) {
            $model = AiModel::query()
                ->whereKey($validated['active_ai_model_id'])
                ->whereHas('provider', fn ($query) => $query->where('type', ProviderType::Ai->value))
                ->firstOrFail();

            $validated['active_ai_model_id'] = $model->id;
        }

        $setting->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI settings updated.']);

        return back();
    }

    public function storeProvider(StoreAiProviderRequest $request): RedirectResponse
    {
        $validated = $this->providerAttributes($request->validated());

        ApiProvider::query()->create($validated + [
            'type' => ProviderType::Ai,
            'status' => ProviderStatus::Unknown,
            'markets' => [],
            'capabilities' => ['summaries'],
            'fetch_limit' => 50,
            'auto_sync_stocks' => false,
            'last_checked_at' => null,
            'last_latency_ms' => null,
            'last_error' => null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI provider created.']);

        return back();
    }

    public function updateProvider(UpdateAiProviderRequest $request, ApiProvider $apiProvider, ProviderHealthService $health): RedirectResponse
    {
        $this->ensureAiProvider($apiProvider);

        $validated = $this->providerAttributes($request->validated(), $apiProvider);

        if (array_key_exists('is_active', $validated) && (bool) $validated['is_active'] !== $apiProvider->is_active) {
            $health->setDisabled($apiProvider, ! $validated['is_active']);
        }

        unset($validated['is_active']);

        $apiProvider->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI provider updated.']);

        return back();
    }

    public function destroyProvider(ApiProvider $apiProvider): RedirectResponse
    {
        $this->ensureAiProvider($apiProvider);

        AiSetting::query()
            ->whereIn('active_ai_model_id', $apiProvider->aiModels()->select('id'))
            ->update(['active_ai_model_id' => null]);

        $apiProvider->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI provider deleted.']);

        return back();
    }

    public function enableProviderModels(ApiProvider $apiProvider): RedirectResponse
    {
        $this->ensureAiProvider($apiProvider);

        $apiProvider->aiModels()->update(['is_active' => true]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI provider models enabled.']);

        return back();
    }

    public function storeModel(StoreAiModelRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $provider = ApiProvider::query()
            ->whereKey($validated['api_provider_id'])
            ->where('type', ProviderType::Ai->value)
            ->firstOrFail();

        $provider->aiModels()->create($this->modelAttributes($validated));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI model created.']);

        return back();
    }

    public function updateModel(StoreAiModelRequest $request, AiModel $aiModel): RedirectResponse
    {
        $this->ensureAiProvider($aiModel->provider);

        $validated = $request->validated();
        $provider = ApiProvider::query()
            ->whereKey($validated['api_provider_id'])
            ->where('type', ProviderType::Ai->value)
            ->firstOrFail();

        $aiModel->update($this->modelAttributes($validated) + ['api_provider_id' => $provider->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI model updated.']);

        return back();
    }

    public function destroyModel(AiModel $aiModel): RedirectResponse
    {
        $this->ensureAiProvider($aiModel->provider);

        AiSetting::query()
            ->where('active_ai_model_id', $aiModel->id)
            ->update(['active_ai_model_id' => null]);

        $aiModel->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI model deleted.']);

        return back();
    }

    public function toggleModel(AiModel $aiModel): RedirectResponse
    {
        $this->ensureAiProvider($aiModel->provider);

        $aiModel->update(['is_active' => ! $aiModel->is_active]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $aiModel->is_active ? 'AI model enabled.' : 'AI model disabled.',
        ]);

        return back();
    }

    public function activateModel(AiModel $aiModel): RedirectResponse
    {
        $this->ensureAiProvider($aiModel->provider);

        AiSetting::current()->update([
            'enabled' => true,
            'active_ai_model_id' => $aiModel->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Active AI model updated.']);

        return back();
    }

    public function testModel(AiModel $aiModel, AiTaskService $tasks, AiProviderClientFactory $clients, ProviderHealthService $health): RedirectResponse
    {
        $this->ensureAiProvider($aiModel->provider);

        return $this->runModelTest($aiModel, $tasks, $clients, $health);
    }

    /**
     * Runtime-aware connection test: chat models do a completion, HF pipeline
     * models exercise their pipeline. Records health on both provider + model.
     */
    private function runModelTest(AiModel $aiModel, AiTaskService $tasks, AiProviderClientFactory $clients, ProviderHealthService $health): RedirectResponse
    {
        $provider = $aiModel->provider;
        $client = $clients->make($provider);

        if ($client === null) {
            $result = new AiCompletionResult(false, error: 'Unsupported AI provider.');
        } else {
            $result = $tasks->test($aiModel);
        }

        $this->recordTestResult($provider, $result, $health);

        if ($result->successful) {
            $tasks->recordSuccess($aiModel, $result->latencyMs);
        } else {
            $tasks->recordFailure($aiModel, $result->error, $result->latencyMs);
        }

        Inertia::flash('toast', [
            'type' => $result->successful ? 'success' : 'error',
            'message' => $result->successful ? 'AI provider connection succeeded.' : ($result->error ?? 'AI provider connection failed.'),
        ]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function providerAttributes(array $validated, ?ApiProvider $existing = null): array
    {
        $validated['base_url'] = isset($validated['base_url']) && trim((string) $validated['base_url']) !== ''
            ? trim((string) $validated['base_url'])
            : null;

        if (($validated['clear_api_key'] ?? false) === true) {
            $validated['api_key'] = null;
        } elseif (array_key_exists('api_key', $validated)) {
            $apiKey = trim((string) $validated['api_key']);

            if ($apiKey === '') {
                unset($validated['api_key']);
            } else {
                $validated['api_key'] = $apiKey;
            }
        }

        unset($validated['clear_api_key']);

        $validated['priority'] = $validated['priority'] ?? $existing?->priority ?? 100;
        $validated['refresh_interval_minutes'] = $validated['refresh_interval_minutes'] ?? $existing?->refresh_interval_minutes ?? 30;
        $validated['auto_recovery'] = $validated['auto_recovery'] ?? $existing?->auto_recovery ?? true;
        $validated['is_active'] = $validated['is_active'] ?? $existing?->is_active ?? true;

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function modelAttributes(array $validated): array
    {
        $endpoint = isset($validated['endpoint_url']) && trim((string) $validated['endpoint_url']) !== ''
            ? trim((string) $validated['endpoint_url'])
            : null;

        return [
            'api_provider_id' => $validated['api_provider_id'],
            'name' => $validated['name'],
            'model' => $validated['model'],
            'task' => $validated['task'] ?? null,
            'runtime' => $validated['runtime'] ?? null,
            'endpoint_url' => $endpoint,
            'is_active' => $validated['is_active'] ?? true,
            'max_output_tokens' => $validated['max_output_tokens'],
            'temperature' => $validated['temperature'] ?? null,
        ];
    }

    /**
     * @param  Collection<int, AiModel>  $models
     * @return array<int, array<string, mixed>>
     */
    private function taskPayloads(Collection $models): array
    {
        $settings = AiTaskSetting::query()->get()->keyBy(fn (AiTaskSetting $s): string => $s->task->value);

        return collect(AiTask::cases())->map(function (AiTask $task) use ($models, $settings): array {
            $setting = $settings->get($task->value);
            $activeId = $setting?->active_ai_model_id;
            $candidates = $models->filter(fn (AiModel $m): bool => $m->task === $task);
            $active = $candidates->firstWhere('id', $activeId);

            return [
                'task' => $task->value,
                'label' => $task->label(),
                'default_runtime' => $task->defaultRuntime()->value,
                'enabled' => (bool) ($setting?->enabled ?? false),
                'active_ai_model_id' => $activeId,
                'fallback_behavior' => $setting?->fallback_behavior,
                'status' => $active?->status->value,
                'status_label' => $active?->status->label(),
                'status_color' => $active?->status->color(),
                'last_error' => $active?->last_error,
                'last_latency_ms' => $active?->last_latency_ms,
                'last_checked_at' => $active?->last_checked_at?->diffForHumans(),
                'models' => $candidates->map(fn (AiModel $m): array => [
                    'value' => $m->id,
                    'label' => "{$m->provider->name} / {$m->name}",
                ])->values()->all(),
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function providerPayload(ApiProvider $provider, ?int $activeModelId): array
    {
        return [
            'id' => $provider->id,
            'key' => $provider->key,
            'name' => $provider->name,
            'base_url' => $provider->base_url,
            'status' => $provider->status->value,
            'status_label' => $provider->status->label(),
            'status_color' => $provider->status->color(),
            'is_active' => $provider->is_active,
            'auto_recovery' => $provider->auto_recovery,
            'api_key_configured' => $provider->hasApiKey(),
            'priority' => $provider->priority,
            'refresh_interval_minutes' => $provider->refresh_interval_minutes,
            'last_latency_ms' => $provider->last_latency_ms,
            'last_error' => $provider->last_error,
            'last_checked_at' => $provider->last_checked_at?->diffForHumans(),
            'models' => $provider->aiModels
                ->map(fn (AiModel $model): array => [
                    'id' => $model->id,
                    'api_provider_id' => $model->api_provider_id,
                    'name' => $model->name,
                    'model' => $model->model,
                    'task' => $model->task?->value,
                    'runtime' => $model->runtime?->value,
                    'endpoint_url' => $model->endpoint_url,
                    'is_active' => $model->is_active,
                    'status' => $model->status->value,
                    'status_label' => $model->status->label(),
                    'status_color' => $model->status->color(),
                    'last_error' => $model->last_error,
                    'last_latency_ms' => $model->last_latency_ms,
                    'last_checked_at' => $model->last_checked_at?->diffForHumans(),
                    'max_output_tokens' => $model->max_output_tokens,
                    'temperature' => $model->temperature,
                    'is_selected' => $model->id === $activeModelId,
                ])
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(AiSetting $setting): array
    {
        if (! $setting->enabled) {
            return [
                'state' => 'disabled',
                'label' => 'Disabled',
                'message' => 'Admin disabled AI summaries.',
                'color' => 'slate',
                'last_error' => null,
                'last_latency_ms' => null,
                'last_checked_at' => null,
            ];
        }

        $model = $setting->activeModel;
        $provider = $model?->provider;

        if ($model === null || ! $model->is_active || $provider === null || ! $provider->hasApiKey()) {
            return [
                'state' => 'not_configured',
                'label' => 'Not configured',
                'message' => 'Select an active model and configure the provider API key.',
                'color' => 'amber',
                'last_error' => $provider?->last_error,
                'last_latency_ms' => $provider?->last_latency_ms,
                'last_checked_at' => $provider?->last_checked_at?->diffForHumans(),
            ];
        }

        if (! $provider->is_active || $provider->status === ProviderStatus::Disabled) {
            return [
                'state' => 'disabled',
                'label' => 'Disabled',
                'message' => 'The active AI provider is disabled.',
                'color' => 'slate',
                'last_error' => $provider->last_error,
                'last_latency_ms' => $provider->last_latency_ms,
                'last_checked_at' => $provider->last_checked_at?->diffForHumans(),
            ];
        }

        if ($provider->status === ProviderStatus::Operational) {
            return [
                'state' => 'operational',
                'label' => 'Operational',
                'message' => "{$provider->name} / {$model->name} passed the last health check.",
                'color' => 'emerald',
                'last_error' => null,
                'last_latency_ms' => $provider->last_latency_ms,
                'last_checked_at' => $provider->last_checked_at?->diffForHumans(),
            ];
        }

        if (in_array($provider->status, [ProviderStatus::Degraded, ProviderStatus::Down], true)) {
            return [
                'state' => $provider->status->value,
                'label' => $provider->status->label(),
                'message' => 'The active AI provider failed its last health check.',
                'color' => $provider->status->color(),
                'last_error' => $provider->last_error,
                'last_latency_ms' => $provider->last_latency_ms,
                'last_checked_at' => $provider->last_checked_at?->diffForHumans(),
            ];
        }

        return [
            'state' => 'unknown',
            'label' => 'Not tested',
            'message' => 'Run a connection test to verify the active AI provider.',
            'color' => 'slate',
            'last_error' => $provider->last_error,
            'last_latency_ms' => $provider->last_latency_ms,
            'last_checked_at' => $provider->last_checked_at?->diffForHumans(),
        ];
    }

    private function recordTestResult(ApiProvider $provider, AiCompletionResult $result, ProviderHealthService $health): void
    {
        $provider->forceFill([
            'last_checked_at' => now(),
            'last_latency_ms' => $result->latencyMs,
        ])->save();

        if ($result->successful) {
            $health->recordSuccess($provider->key, 'ai_connection_test');

            return;
        }

        $health->recordFailure($provider->key, $result->error ?? 'AI provider connection failed.');
    }

    private function ensureAiProvider(ApiProvider $provider): void
    {
        abort_unless($provider->type === ProviderType::Ai, 404);
    }
}
