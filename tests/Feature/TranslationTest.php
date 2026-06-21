<?php

declare(strict_types=1);

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Enums\StockSignal;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\AiTaskSetting;
use App\Models\ApiProvider;
use App\Models\NewsItem;
use App\Models\NewsItemTranslation;
use App\Models\NewsSource;
use App\Models\Stock;
use App\Models\StockAiAnalysis;
use App\Services\Translation\ContentTranslationService;
use App\Services\Translation\DeepLTranslator;
use App\Support\Presenters\NewsPresenter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function translationAiProvider(array $attributes = []): ApiProvider
{
    return ApiProvider::factory()->create(array_merge([
        'key' => 'huggingface',
        'name' => 'Hugging Face',
        'type' => ProviderType::Ai,
        'base_url' => null,
        'api_key' => 'hf-secret',
        'status' => ProviderStatus::Operational,
        'is_active' => true,
        'capabilities' => ['translation'],
        'markets' => [],
    ], $attributes));
}

function translationAiModel(ApiProvider $provider, array $attributes = []): AiModel
{
    return AiModel::factory()
        ->for($provider, 'provider')
        ->create(array_merge([
            'name' => 'Qwen translation',
            'model' => 'Qwen/Qwen3-8B',
            'task' => AiTask::Translation,
            'runtime' => AiRuntime::OpenAiChat,
            'endpoint_url' => 'https://translate.endpoints.huggingface.cloud',
            'is_active' => true,
            'status' => ProviderStatus::Operational,
            'max_output_tokens' => 900,
            'temperature' => 0.1,
        ], $attributes));
}

function enableTranslationTask(AiModel $model): void
{
    AiSetting::query()->create(['enabled' => true]);

    AiTaskSetting::query()->create([
        'task' => AiTask::Translation->value,
        'enabled' => true,
        'active_ai_model_id' => $model->id,
    ]);
}

it('prefers cached news translations for the requested locale', function () {
    $source = NewsSource::factory()->create(['language' => 'en']);
    $news = NewsItem::factory()->for($source, 'source')->create([
        'title' => 'Company beats expectations',
        'summary' => 'The company reported stronger revenue.',
        'ai_summary' => 'Revenue was stronger than expected.',
    ]);

    NewsItemTranslation::factory()->for($news, 'newsItem')->create([
        'locale' => 'tr',
        'title' => 'Şirket beklentileri aştı',
        'summary' => 'Gelir beklentiden güçlü geldi.',
    ]);

    $payload = NewsPresenter::card($news->load('translations'), 'tr');

    expect($payload['title'])->toBe('Şirket beklentileri aştı')
        ->and($payload['summary'])->toBe('Gelir beklentiden güçlü geldi.')
        ->and($payload['has_translation'])->toBeTrue()
        ->and($payload['translation_locale'])->toBe('tr');
});

it('falls back to the original news text when translation cache is missing', function () {
    $news = NewsItem::factory()->create([
        'title' => 'Original title',
        'summary' => 'Original summary',
        'ai_summary' => null,
    ]);

    $payload = NewsPresenter::card($news->load('translations'), 'tr');

    expect($payload['title'])->toBe('Original title')
        ->and($payload['summary'])->toBe('Original summary')
        ->and($payload['has_translation'])->toBeFalse();
});

it('sends DeepL translation requests from encrypted AI provider settings', function () {
    $provider = translationAiProvider([
        'key' => 'deepl',
        'name' => 'DeepL',
        'base_url' => 'https://api-free.deepl.com/v2',
        'api_key' => 'test-deepl-key',
    ]);
    $model = translationAiModel($provider, [
        'name' => 'DeepL Translate',
        'model' => 'deepl-api',
        'runtime' => AiRuntime::DeepLTranslation,
        'endpoint_url' => null,
    ]);

    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response([
            'translations' => [
                ['detected_source_language' => 'EN', 'text' => 'Merhaba'],
            ],
        ]),
    ]);

    $result = app(DeepLTranslator::class)->translate($model, ['Hello'], 'tr', 'en');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api-free.deepl.com/v2/translate'
        && $request->hasHeader('Authorization', 'DeepL-Auth-Key test-deepl-key')
        && $request->data()['text'] === ['Hello']
        && $request->data()['target_lang'] === 'TR'
        && $request->data()['source_lang'] === 'EN');

    expect($result?->texts)->toBe(['Merhaba'])
        ->and($result?->detectedSourceLanguage)->toBe('EN');
});

it('uses the active Hugging Face translation task to cache translated stock analysis fields', function () {
    $model = translationAiModel(translationAiProvider());
    enableTranslationTask($model);

    Http::preventStrayRequests();
    Http::fake([
        'translate.endpoints.huggingface.cloud/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['content' => '{"translations":["Summary","Driver","Risk","Disclaimer"]}'],
            ]],
        ]),
    ]);

    $stock = Stock::factory()->create();
    $analysis = StockAiAnalysis::query()->create([
        'stock_id' => $stock->id,
        'signal' => StockSignal::Neutral,
        'confidence' => 55,
        'summary' => 'Özet',
        'drivers' => ['Etken'],
        'risks' => ['Risk'],
        'disclaimer' => 'Uyarı',
        'generated_at' => now(),
    ])->load('translations');

    app(ContentTranslationService::class)
        ->translateStockAnalysis($analysis, 'en');

    $translation = $analysis->translations()->where('locale', 'en')->first();

    expect($translation?->summary)->toBe('Summary')
        ->and($translation?->drivers)->toBe(['Driver'])
        ->and($translation?->risks)->toBe(['Risk'])
        ->and($translation?->disclaimer)->toBe('Disclaimer')
        ->and($translation?->provider)->toBe('huggingface');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://translate.endpoints.huggingface.cloud/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer hf-secret')
        && $request->data()['model'] === 'Qwen/Qwen3-8B'
        && str_contains((string) data_get($request->data(), 'messages.0.content'), 'do not summarize')
        && str_contains((string) data_get($request->data(), 'messages.0.content'), 'same level of specificity')
        && str_contains((string) data_get($request->data(), 'messages.0.content'), 'Do not turn concrete financial statements into generic statements')
        && str_contains((string) data_get($request->data(), 'messages.0.content'), 'professional finance terminology')
        && str_contains((string) data_get($request->data(), 'messages.1.content'), '"target_locale":"en"')
        && str_contains((string) data_get($request->data(), 'messages.1.content'), '"Özet"'));
});

it('does not dispatch translation HTTP calls when the translation task is disabled', function () {
    $model = translationAiModel(translationAiProvider());

    AiSetting::query()->create(['enabled' => true]);
    AiTaskSetting::query()->create([
        'task' => AiTask::Translation->value,
        'enabled' => false,
        'active_ai_model_id' => $model->id,
    ]);

    Http::fake();

    $news = NewsItem::factory()->for(NewsSource::factory()->create(['language' => 'en']), 'source')->create([
        'title' => 'Original title',
        'summary' => 'Original summary',
        'ai_summary' => null,
    ]);

    $result = app(ContentTranslationService::class)->translateNewsItem($news->load(['source', 'translations']), 'tr');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});
