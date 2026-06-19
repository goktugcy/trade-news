<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiModelRequest;
use App\Http\Requests\Admin\StoreAiProviderRequest;
use App\Http\Requests\Admin\UpdateAiProviderRequest;
use App\Http\Requests\Admin\UpdateAiSettingsRequest;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\ApiProvider;
use App\Services\Ai\AiCompletionResult;
use App\Services\Ai\AiProviderClientFactory;
use App\Services\Providers\ProviderHealthService;
use Illuminate\Http\RedirectResponse;
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

        return Inertia::render('admin/AiSettings', [
            'settings' => [
                'enabled' => $setting->enabled,
                'active_ai_model_id' => $setting->active_ai_model_id,
            ],
            'status' => $this->statusPayload($setting),
            'providers' => $providers->map(fn (ApiProvider $provider): array => $this->providerPayload($provider, $setting->active_ai_model_id))->values(),
            'providerOptions' => AiProviderClientFactory::providerOptions(),
        ]);
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

    public function testModel(AiModel $aiModel, AiProviderClientFactory $clients, ProviderHealthService $health): RedirectResponse
    {
        $provider = $aiModel->provider;
        $this->ensureAiProvider($provider);

        $client = $clients->make($provider);

        if ($client === null) {
            $result = new AiCompletionResult(false, error: 'Unsupported AI provider.');
        } else {
            $result = $client->complete(
                $aiModel,
                'Reply with exactly OK.',
                'You are a provider health check. Return only OK and no other text.',
            );
        }

        $this->recordTestResult($provider, $result, $health);

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
        return [
            'api_provider_id' => $validated['api_provider_id'],
            'name' => $validated['name'],
            'model' => $validated['model'],
            'is_active' => $validated['is_active'] ?? true,
            'max_output_tokens' => $validated['max_output_tokens'],
            'temperature' => $validated['temperature'] ?? null,
        ];
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
                    'is_active' => $model->is_active,
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
