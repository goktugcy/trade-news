<?php

declare(strict_types=1);

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Enums\ProviderType;
use App\Enums\Sentiment;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\AiTaskSetting;
use App\Models\ApiProvider;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\User;
use App\Services\Ai\AiProviderClientFactory;
use App\Services\Ai\EmbeddingService;
use App\Services\Ai\HuggingFaceEndpointClient;
use App\Services\News\NewsSentimentService;
use Database\Seeders\AiTaskSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function hfProvider(array $attributes = []): ApiProvider
{
    return ApiProvider::factory()->create(array_merge([
        'key' => 'huggingface',
        'name' => 'Hugging Face',
        'type' => ProviderType::Ai,
        'base_url' => null,
        'api_key' => 'hf-secret',
        'is_active' => true,
    ], $attributes));
}

function hfModel(ApiProvider $provider, AiTask $task, AiRuntime $runtime, string $endpoint): AiModel
{
    return AiModel::factory()->for($provider, 'provider')->create([
        'name' => 'HF '.$task->value,
        'model' => $task->value.'-model',
        'task' => $task,
        'runtime' => $runtime,
        'endpoint_url' => $endpoint,
        'is_active' => true,
    ]);
}

it('seeds Hugging Face models without defaulting to the serverless Inference API', function () {
    $provider = hfProvider();

    $this->seed(AiTaskSeeder::class);

    expect(AiModel::query()->where('api_provider_id', $provider->id)->count())->toBeGreaterThan(0)
        ->and(AiModel::query()->where('api_provider_id', $provider->id)->where('task', AiTask::Translation->value)->exists())->toBeTrue()
        ->and(AiModel::query()->where('api_provider_id', $provider->id)->whereNotNull('endpoint_url')->count())->toBe(0);
});

it('preserves dedicated endpoints and clears deprecated serverless Inference API endpoints', function () {
    $provider = hfProvider();

    $dedicated = AiModel::factory()->for($provider, 'provider')->create([
        'name' => 'Qwen3 8B (summary)',
        'model' => 'Qwen/Qwen3-8B',
        'task' => AiTask::Summary,
        'runtime' => AiRuntime::OpenAiChat,
        'endpoint_url' => 'https://summary.endpoints.huggingface.cloud',
    ]);

    $legacy = AiModel::factory()->for($provider, 'provider')->create([
        'name' => 'Legacy HF model',
        'model' => 'legacy/custom-model',
        'task' => AiTask::EntityEn,
        'runtime' => AiRuntime::HfTokenClassification,
        'endpoint_url' => 'https://api-inference.huggingface.co/models/legacy/custom-model',
    ]);

    $this->seed(AiTaskSeeder::class);

    expect($dedicated->fresh()->endpoint_url)->toBe('https://summary.endpoints.huggingface.cloud')
        ->and($legacy->fresh()->endpoint_url)->toBeNull()
        ->and(AiModel::query()->where('endpoint_url', 'like', 'https://api-inference.huggingface.co%')->count())->toBe(0);
});

it('sends an OpenAI-compatible chat request for the chat runtime', function () {
    Http::preventStrayRequests();
    Http::fake([
        'ep.endpoints.huggingface.cloud/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'A concise summary.']]],
        ], 200),
    ]);

    $model = hfModel(hfProvider(), AiTask::Summary, AiRuntime::OpenAiChat, 'https://ep.endpoints.huggingface.cloud');
    $client = app(AiProviderClientFactory::class)->make($model->provider);

    expect($client)->toBeInstanceOf(HuggingFaceEndpointClient::class);

    $result = $client->complete($model, 'Summarize this.', 'You summarize.');

    expect($result->successful)->toBeTrue()
        ->and($result->text)->toBe('A concise summary.');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://ep.endpoints.huggingface.cloud/v1/chat/completions'
        && $r->hasHeader('Authorization', 'Bearer hf-secret')
        && $r->data()['model'] === 'summary-model'
        && $r->data()['messages'][1]['content'] === 'Summarize this.');
});

it('does not duplicate the v1 path when a chat endpoint already ends with v1', function () {
    Http::preventStrayRequests();
    Http::fake([
        'ep.endpoints.huggingface.cloud/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'A concise summary.']]],
        ], 200),
    ]);

    $model = hfModel(hfProvider(), AiTask::Summary, AiRuntime::OpenAiChat, 'https://ep.endpoints.huggingface.cloud/v1');

    app(AiProviderClientFactory::class)->huggingFace()->complete($model, 'Summarize this.', 'You summarize.');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://ep.endpoints.huggingface.cloud/v1/chat/completions');
});

it('sends a text-classification request and returns scores', function () {
    Http::preventStrayRequests();
    Http::fake([
        'cls.hf.cloud' => Http::response([[
            ['label' => 'positive', 'score' => 0.91],
            ['label' => 'negative', 'score' => 0.09],
        ]], 200),
    ]);

    $model = hfModel(hfProvider(), AiTask::SentimentEn, AiRuntime::HfTextClassification, 'https://cls.hf.cloud');
    $client = app(AiProviderClientFactory::class)->huggingFace();

    $result = $client->classify($model, 'Earnings beat expectations.');

    expect($result->successful)->toBeTrue()
        ->and($result->scores[0]['label'])->toBe('positive')
        ->and($result->scores[0]['score'])->toBe(0.91);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://cls.hf.cloud'
        && $r->hasHeader('Authorization', 'Bearer hf-secret')
        && $r->data()['inputs'] === 'Earnings beat expectations.');
});

it('sends a token-classification request and returns entities', function () {
    Http::preventStrayRequests();
    Http::fake([
        'ner.hf.cloud' => Http::response([
            ['entity_group' => 'ORG', 'word' => 'Apple', 'score' => 0.99, 'start' => 0, 'end' => 5],
        ], 200),
    ]);

    $model = hfModel(hfProvider(), AiTask::EntityEn, AiRuntime::HfTokenClassification, 'https://ner.hf.cloud');
    $result = app(AiProviderClientFactory::class)->huggingFace()->tokenClassify($model, 'Apple rose.');

    expect($result->successful)->toBeTrue()
        ->and($result->entities[0]['entity_group'])->toBe('ORG')
        ->and($result->entities[0]['word'])->toBe('Apple');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://ner.hf.cloud'
        && $r->data()['inputs'] === 'Apple rose.');
});

it('sends a feature-extraction request and returns an embedding vector', function () {
    Http::preventStrayRequests();
    Http::fake([
        'emb.hf.cloud' => Http::response([0.1, 0.2, 0.3], 200),
    ]);

    $model = hfModel(hfProvider(), AiTask::Embedding, AiRuntime::HfFeatureExtraction, 'https://emb.hf.cloud');
    $result = app(AiProviderClientFactory::class)->huggingFace()->featureExtract($model, 'embed me');

    expect($result->successful)->toBeTrue()
        ->and($result->embedding)->toBe([0.1, 0.2, 0.3]);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://emb.hf.cloud'
        && $r->data()['inputs'] === 'embed me');
});

it('falls back to sentence-similarity payload for E5 endpoints deployed as sentence similarity', function () {
    Http::preventStrayRequests();
    Http::fake([
        'emb.hf.cloud' => Http::sequence()
            ->push(['error' => "SentenceSimilarityPipeline.__call__() missing 1 required positional argument: 'sentences'"], 400)
            ->push([1.0], 200),
    ]);

    $model = hfModel(hfProvider(), AiTask::Embedding, AiRuntime::HfFeatureExtraction, 'https://emb.hf.cloud');
    $result = app(AiProviderClientFactory::class)->huggingFace()->featureExtract($model, 'connection test');

    expect($result->successful)->toBeTrue()
        ->and($result->embedding)->toBeNull()
        ->and($result->scores[0]['score'])->toBe(1.0);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://emb.hf.cloud'
        && data_get($r->data(), 'inputs.source_sentence') === 'connection test'
        && data_get($r->data(), 'inputs.sentences') === ['connection test']);
});

it('uses sentence-similarity scores for embedding task entity linking', function () {
    Http::preventStrayRequests();
    Http::fake([
        'emb.hf.cloud' => Http::response([0.93, 0.12], 200),
    ]);

    $model = hfModel(hfProvider(), AiTask::Embedding, AiRuntime::HfFeatureExtraction, 'https://emb.hf.cloud');

    AiSetting::query()->create(['enabled' => true]);
    AiTaskSetting::query()->create([
        'task' => AiTask::Embedding->value,
        'enabled' => true,
        'active_ai_model_id' => $model->id,
    ]);

    $scores = app(EmbeddingService::class)->similarity('query: Apple', [
        'passage: AAPL Apple Inc.',
        'passage: MSFT Microsoft Corporation',
    ]);

    expect($scores)->toBe([0.93, 0.12]);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://emb.hf.cloud'
        && data_get($r->data(), 'inputs.source_sentence') === 'query: Apple'
        && data_get($r->data(), 'inputs.sentences.0') === 'passage: AAPL Apple Inc.');
});

it('sends a cross-encoder reranking request as sentence pairs and ranks by score', function () {
    Http::preventStrayRequests();
    Http::fake([
        // text-classification cross-encoder: one relevance score per pair.
        'rank.hf.cloud' => Http::response([
            ['label' => 'LABEL_1', 'score' => 0.2],
            ['label' => 'LABEL_1', 'score' => 0.9],
        ], 200),
    ]);

    $model = hfModel(hfProvider(), AiTask::Reranker, AiRuntime::HfRanking, 'https://rank.hf.cloud');
    $result = app(AiProviderClientFactory::class)->huggingFace()->rank($model, 'query', ['doc a', 'doc b']);

    expect($result->successful)->toBeTrue()
        // doc b (index 1) has the higher score, so it ranks first.
        ->and($result->scores[0]['label'])->toBe('1');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://rank.hf.cloud'
        && $r->data()['inputs'] === [
            ['text' => 'query', 'text_pair' => 'doc a'],
            ['text' => 'query', 'text_pair' => 'doc b'],
        ]);
});

it('encrypts the Hugging Face token and never exposes it in props', function () {
    $admin = User::factory()->admin()->create();
    $provider = hfProvider(['api_key' => 'hf-plaintext']);

    $raw = DB::table('api_providers')->where('id', $provider->id)->value('api_key');

    expect($raw)->not->toBe('hf-plaintext')
        ->and($provider->fresh()->api_key)->toBe('hf-plaintext');

    $this->actingAs($admin)
        ->get('/admin/ai-settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/AiSettings')
            ->where('providers.0.api_key_configured', true)
            ->missing('providers.0.api_key'));
});

it('preserves the HF token on blank update and clears it with the clear flag', function () {
    $admin = User::factory()->admin()->create();
    $provider = hfProvider(['api_key' => 'keep-me']);

    $payload = [
        'key' => 'huggingface',
        'name' => 'Hugging Face',
        'base_url' => '',
        'api_key' => '',
        'clear_api_key' => false,
        'is_active' => true,
        'auto_recovery' => true,
        'priority' => 90,
        'refresh_interval_minutes' => 30,
    ];

    $this->actingAs($admin)->put("/admin/ai-settings/providers/{$provider->id}", $payload)->assertRedirect();
    expect($provider->fresh()->api_key)->toBe('keep-me');

    $this->actingAs($admin)->put("/admin/ai-settings/providers/{$provider->id}", array_merge($payload, ['clear_api_key' => true]))->assertRedirect();
    expect($provider->fresh()->api_key)->toBeNull();
});

it('renders the AI task matrix on the settings page', function () {
    $admin = User::factory()->admin()->create();

    foreach (AiTask::cases() as $task) {
        AiTaskSetting::query()->firstOrCreate(['task' => $task->value], ['enabled' => false]);
    }

    $this->actingAs($admin)
        ->get('/admin/ai-settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/AiSettings')
            ->has('tasks', count(AiTask::cases()))
            ->has('taskOptions', count(AiTask::cases()))
            ->has('runtimeOptions', count(AiRuntime::cases()))
            ->where('tasks.0.task', AiTask::cases()[0]->value));
});

it('uses HF sentiment when the task is enabled and falls back when disabled', function () {
    AiSetting::query()->create(['enabled' => true]);
    $provider = hfProvider();
    $model = hfModel($provider, AiTask::SentimentEn, AiRuntime::HfTextClassification, 'https://senti.hf.cloud');

    AiTaskSetting::query()->create([
        'task' => AiTask::SentimentEn->value,
        'enabled' => true,
        'active_ai_model_id' => $model->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'senti.hf.cloud' => Http::response([[
            ['label' => 'negative', 'score' => 0.95],
            ['label' => 'positive', 'score' => 0.05],
        ]], 200),
    ]);

    // Positive lexicon wording, but HF says negative → HF wins when enabled.
    $source = NewsSource::factory()->create(['language' => 'en']);
    $item = NewsItem::factory()->for($source, 'source')->create(['title' => 'Company beats earnings record surge', 'summary' => 'Strong gains.']);

    app(NewsSentimentService::class)->applyTo($item->fresh());

    expect($item->fresh()->sentiment)->toBe(Sentiment::Negative);

    // Disable the task → deterministic lexicon (positive) is used.
    AiTaskSetting::query()->where('task', AiTask::SentimentEn->value)->update(['enabled' => false]);

    $item2 = NewsItem::factory()->for($source, 'source')->create(['title' => 'Company beats earnings record surge', 'summary' => 'Strong gains.']);
    app(NewsSentimentService::class)->applyTo($item2->fresh());

    expect($item2->fresh()->sentiment)->toBe(Sentiment::Positive);
});
