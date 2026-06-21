<?php

declare(strict_types=1);

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Enums\Market;
use App\Enums\ProviderType;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\AiTaskSetting;
use App\Models\ApiProvider;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\Stock;
use App\Services\News\NewsEntityEnhancer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function entityEnhancerProvider(): ApiProvider
{
    return ApiProvider::factory()->create([
        'key' => 'huggingface',
        'name' => 'Hugging Face',
        'type' => ProviderType::Ai,
        'base_url' => null,
        'api_key' => 'hf-secret',
        'is_active' => true,
    ]);
}

function entityEnhancerModel(ApiProvider $provider, AiTask $task, string $endpoint): AiModel
{
    return AiModel::factory()->for($provider, 'provider')->create([
        'name' => 'HF '.$task->value,
        'model' => $task->value.'-model',
        'task' => $task,
        'runtime' => AiRuntime::HfTokenClassification,
        'endpoint_url' => $endpoint,
        'is_active' => true,
    ]);
}

function enableEntityEnhancerTask(AiTask $task, AiModel $model): void
{
    AiSetting::query()->create(['enabled' => true]);

    AiTaskSetting::query()->create([
        'task' => $task->value,
        'enabled' => true,
        'active_ai_model_id' => $model->id,
    ]);
}

it('links high confidence Turkish NER entities and ignores low confidence entities', function () {
    Http::preventStrayRequests();
    Http::fake([
        'ner.tr.hf.cloud' => Http::response([
            ['entity_group' => 'ORG', 'word' => 'Türk Hava Yolları', 'score' => 0.94],
            ['entity_group' => 'ORG', 'word' => 'Apple', 'score' => 0.52],
        ], 200),
    ]);

    $provider = entityEnhancerProvider();
    $model = entityEnhancerModel($provider, AiTask::EntityTr, 'https://ner.tr.hf.cloud');
    enableEntityEnhancerTask(AiTask::EntityTr, $model);

    Stock::factory()->bist()->create([
        'symbol' => 'THYAO',
        'name' => 'Türk Hava Yolları',
        'aliases' => ['THYAO', 'Türk Hava Yolları', 'Turkish Airlines', 'THY'],
    ]);

    Stock::factory()->nasdaq()->create([
        'symbol' => 'AAPL',
        'name' => 'Apple Inc.',
        'aliases' => ['Apple', 'AAPL'],
    ]);

    $source = NewsSource::factory()->create(['language' => 'tr']);
    $news = NewsItem::factory()->for($source, 'source')->create([
        'title' => 'Türk Hava Yolları yeni hedeflerini açıkladı',
        'summary' => null,
        'content' => null,
        'market' => Market::BIST,
    ]);

    $added = app(NewsEntityEnhancer::class)->enhance($news);

    expect($added)->toBe(1)
        ->and($news->stocks()->pluck('symbol')->all())->toBe(['THYAO']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ner.tr.hf.cloud'
        && $request->hasHeader('Authorization', 'Bearer hf-secret')
        && str_contains((string) $request->data()['inputs'], 'Türk Hava Yolları'));
});

it('does not link entity text that only contains a stock alias inside a longer word', function () {
    Http::preventStrayRequests();
    Http::fake([
        'ner.en.hf.cloud' => Http::response([
            ['entity_group' => 'ORG', 'word' => 'Metaverse', 'score' => 0.99],
        ], 200),
    ]);

    $provider = entityEnhancerProvider();
    $model = entityEnhancerModel($provider, AiTask::EntityEn, 'https://ner.en.hf.cloud');
    enableEntityEnhancerTask(AiTask::EntityEn, $model);

    Stock::factory()->nasdaq()->create([
        'symbol' => 'META',
        'name' => 'Meta Platforms Inc.',
        'aliases' => ['Meta'],
    ]);

    $source = NewsSource::factory()->create(['language' => 'en']);
    $news = NewsItem::factory()->for($source, 'source')->create([
        'title' => 'Metaverse adoption accelerates among online communities',
        'summary' => null,
        'content' => null,
        'market' => Market::NASDAQ,
    ]);

    $added = app(NewsEntityEnhancer::class)->enhance($news);

    expect($added)->toBe(0)
        ->and($news->stocks()->pluck('symbol')->all())->toBe([]);
});
