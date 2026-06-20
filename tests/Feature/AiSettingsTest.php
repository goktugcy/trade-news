<?php

declare(strict_types=1);

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\ApiProvider;
use App\Models\User;
use App\Services\Ai\AiProviderClientFactory;
use App\Services\News\AiSummarizerInterface;
use App\Services\News\NullSummarizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function aiSettingsProvider(array $attributes = []): ApiProvider
{
    return ApiProvider::factory()->create(array_merge([
        'key' => 'openai',
        'name' => 'OpenAI',
        'type' => ProviderType::Ai,
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'ai-secret',
        'status' => ProviderStatus::Operational,
        'is_active' => true,
        'capabilities' => ['summaries'],
        'markets' => [],
    ], $attributes));
}

function aiSettingsModel(ApiProvider $provider, array $attributes = []): AiModel
{
    return AiModel::factory()
        ->for($provider, 'provider')
        ->create(array_merge([
            'name' => 'GPT Mini',
            'model' => 'gpt-4o-mini',
            'is_active' => true,
            'max_output_tokens' => 160,
            'temperature' => 0.3,
        ], $attributes));
}

it('renders the admin AI settings page and hides plaintext provider keys', function () {
    $admin = User::factory()->admin()->create();
    $provider = aiSettingsProvider(['api_key' => 'plain-secret']);
    $model = aiSettingsModel($provider);

    AiSetting::factory()->create([
        'enabled' => true,
        'active_ai_model_id' => $model->id,
    ]);

    $rawApiKey = DB::table('api_providers')->where('id', $provider->id)->value('api_key');

    expect($rawApiKey)->not->toBe('plain-secret')
        ->and($provider->fresh()->api_key)->toBe('plain-secret');

    $this->actingAs($admin)
        ->get('/admin/ai-settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/AiSettings')
            ->where('settings.enabled', true)
            ->where('settings.active_ai_model_id', $model->id)
            ->where('providers.0.api_key_configured', true)
            ->missing('providers.0.api_key'));
});

it('preserves AI provider keys on blank update and clears them only with the clear flag', function () {
    $admin = User::factory()->admin()->create();
    $provider = aiSettingsProvider(['api_key' => 'original-secret']);

    $payload = [
        'key' => $provider->key,
        'name' => $provider->name,
        'base_url' => $provider->base_url,
        'api_key' => '',
        'clear_api_key' => false,
        'is_active' => true,
        'auto_recovery' => true,
        'priority' => 25,
        'refresh_interval_minutes' => 10,
    ];

    $this->actingAs($admin)
        ->put("/admin/ai-settings/providers/{$provider->id}", $payload)
        ->assertRedirect();

    expect($provider->fresh()->api_key)->toBe('original-secret');

    $this->actingAs($admin)
        ->put("/admin/ai-settings/providers/{$provider->id}", array_merge($payload, ['clear_api_key' => true]))
        ->assertRedirect();

    expect($provider->fresh()->api_key)->toBeNull();
});

it('uses NullSummarizer behavior when no active AI model is configured', function () {
    AiSetting::factory()->create([
        'enabled' => true,
        'active_ai_model_id' => null,
    ]);

    $this->app->forgetInstance(AiSummarizerInterface::class);

    $summarizer = app(AiSummarizerInterface::class);

    expect($summarizer)->toBeInstanceOf(NullSummarizer::class)
        ->and($summarizer->isEnabled())->toBeFalse();
});

it('can enable multiple models under the same AI provider without disabling siblings', function () {
    $admin = User::factory()->admin()->create();
    $provider = aiSettingsProvider([
        'key' => 'huggingface',
        'name' => 'Hugging Face',
        'base_url' => null,
    ]);

    $summary = aiSettingsModel($provider, [
        'name' => 'Qwen summary',
        'model' => 'Qwen/Qwen3-8B',
        'task' => AiTask::Summary,
        'runtime' => AiRuntime::OpenAiChat,
        'is_active' => false,
    ]);
    $sentiment = aiSettingsModel($provider, [
        'name' => 'FinBERT',
        'model' => 'ProsusAI/finbert',
        'task' => AiTask::SentimentEn,
        'runtime' => AiRuntime::HfTextClassification,
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->patch("/admin/ai-settings/models/{$summary->id}/toggle")
        ->assertRedirect();

    expect($summary->fresh()->is_active)->toBeTrue()
        ->and($sentiment->fresh()->is_active)->toBeTrue();
});

it('can enable all models for an AI provider at once', function () {
    $admin = User::factory()->admin()->create();
    $provider = aiSettingsProvider([
        'key' => 'huggingface',
        'name' => 'Hugging Face',
        'base_url' => null,
    ]);

    $summary = aiSettingsModel($provider, [
        'name' => 'Qwen summary',
        'model' => 'Qwen/Qwen3-8B',
        'task' => AiTask::Summary,
        'runtime' => AiRuntime::OpenAiChat,
        'is_active' => false,
    ]);
    $sentiment = aiSettingsModel($provider, [
        'name' => 'FinBERT',
        'model' => 'ProsusAI/finbert',
        'task' => AiTask::SentimentEn,
        'runtime' => AiRuntime::HfTextClassification,
        'is_active' => false,
    ]);

    $this->actingAs($admin)
        ->patch("/admin/ai-settings/providers/{$provider->id}/models/enable")
        ->assertRedirect();

    expect($summary->fresh()->is_active)->toBeTrue()
        ->and($sentiment->fresh()->is_active)->toBeTrue();
});

it('AiProvider clients send the expected request for OpenAI, Anthropic, Gemini, and Grok', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(['output_text' => 'OK'], 200),
        'api.x.ai/v1/responses' => Http::response(['output_text' => 'OK'], 200),
        'api.anthropic.com/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'OK']]], 200),
        'generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'OK']]],
            ]],
        ], 200),
    ]);

    $providers = [
        'openai' => aiSettingsProvider([
            'key' => 'openai',
            'name' => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'openai-secret',
        ]),
        'grok' => aiSettingsProvider([
            'key' => 'grok',
            'name' => 'Grok',
            'base_url' => 'https://api.x.ai/v1',
            'api_key' => 'grok-secret',
        ]),
        'anthropic' => aiSettingsProvider([
            'key' => 'anthropic',
            'name' => 'Anthropic',
            'base_url' => 'https://api.anthropic.com',
            'api_key' => 'anthropic-secret',
        ]),
        'gemini' => aiSettingsProvider([
            'key' => 'gemini',
            'name' => 'Gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'api_key' => 'gemini-secret',
        ]),
    ];

    $models = [
        'openai' => aiSettingsModel($providers['openai'], ['model' => 'gpt-test', 'max_output_tokens' => 23]),
        'grok' => aiSettingsModel($providers['grok'], ['model' => 'grok-test', 'max_output_tokens' => 24]),
        'anthropic' => aiSettingsModel($providers['anthropic'], ['model' => 'claude-test', 'max_output_tokens' => 25]),
        'gemini' => aiSettingsModel($providers['gemini'], ['model' => 'gemini-test', 'max_output_tokens' => 26]),
    ];

    $factory = app(AiProviderClientFactory::class);

    foreach ($providers as $key => $provider) {
        $result = $factory->make($provider)?->complete($models[$key], 'Say OK.', 'Return OK.');

        expect($result?->successful)->toBeTrue();
    }

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer openai-secret')
        && $request->data()['model'] === 'gpt-test'
        && $request->data()['input'] === 'Say OK.'
        && $request->data()['max_output_tokens'] === 23);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.ai/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer grok-secret')
        && $request->data()['model'] === 'grok-test');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages'
        && $request->hasHeader('x-api-key', 'anthropic-secret')
        && $request->hasHeader('anthropic-version', '2023-06-01')
        && $request->data()['model'] === 'claude-test'
        && $request->data()['max_tokens'] === 25);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent'
        && $request->hasHeader('x-goog-api-key', 'gemini-secret')
        && data_get($request->data(), 'generationConfig.maxOutputTokens') === 26
        && data_get($request->data(), 'contents.0.parts.0.text') === 'Say OK.');
});

it('marks an AI provider operational after a successful test connection', function () {
    $admin = User::factory()->admin()->create();
    $provider = aiSettingsProvider([
        'status' => ProviderStatus::Unknown,
        'api_key' => 'openai-secret',
    ]);
    $model = aiSettingsModel($provider);

    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(['output_text' => 'OK'], 200),
    ]);

    $this->actingAs($admin)
        ->post("/admin/ai-settings/models/{$model->id}/test")
        ->assertRedirect();

    $provider->refresh();

    expect($provider->status)->toBe(ProviderStatus::Operational)
        ->and($provider->last_error)->toBeNull()
        ->and($provider->last_checked_at)->not->toBeNull()
        ->and($provider->last_latency_ms)->not->toBeNull();
});

it('updates AI provider status and last error after a failed test connection', function () {
    config([
        'tradenews.providers.health.degraded_after' => 1,
        'tradenews.providers.health.down_after' => 2,
    ]);

    $admin = User::factory()->admin()->create();
    $provider = aiSettingsProvider([
        'status' => ProviderStatus::Operational,
        'api_key' => 'openai-secret',
    ]);
    $model = aiSettingsModel($provider);

    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'invalid key']], 401),
    ]);

    $this->actingAs($admin)
        ->post("/admin/ai-settings/models/{$model->id}/test")
        ->assertRedirect();

    $provider->refresh();

    expect($provider->status)->toBe(ProviderStatus::Degraded)
        ->and($provider->last_error)->toContain('HTTP 401')
        ->and($provider->last_error)->toContain('invalid key')
        ->and($provider->last_checked_at)->not->toBeNull()
        ->and($provider->last_latency_ms)->not->toBeNull();
});
