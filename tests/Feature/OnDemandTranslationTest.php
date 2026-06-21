<?php

declare(strict_types=1);

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Enums\StockSignal;
use App\Jobs\TranslateNewsItemJob;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\AiTaskSetting;
use App\Models\ApiProvider;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\Stock;
use App\Models\StockAiAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function enableChatTranslation(): AiModel
{
    $provider = ApiProvider::factory()->create([
        'key' => 'huggingface',
        'name' => 'Hugging Face',
        'type' => ProviderType::Ai,
        'api_key' => 'hf-secret',
        'status' => ProviderStatus::Operational,
        'is_active' => true,
    ]);

    $model = AiModel::factory()->for($provider, 'provider')->create([
        'name' => 'Qwen translation',
        'model' => 'Qwen/Qwen3-8B',
        'task' => AiTask::Translation,
        'runtime' => AiRuntime::OpenAiChat,
        'endpoint_url' => 'https://translate.endpoints.huggingface.cloud',
        'is_active' => true,
        'status' => ProviderStatus::Operational,
    ]);

    AiSetting::query()->create(['enabled' => true]);
    AiTaskSetting::query()->create([
        'task' => AiTask::Translation->value,
        'enabled' => true,
        'active_ai_model_id' => $model->id,
    ]);

    return $model;
}

function fakeChatTranslations(array $texts): void
{
    Http::preventStrayRequests();
    Http::fake([
        'translate.endpoints.huggingface.cloud/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['translations' => $texts])]]],
        ]),
    ]);
}

it('does not auto-queue translations when viewing the news feed', function () {
    enableChatTranslation();
    Queue::fake();

    $user = User::factory()->create(['locale' => 'tr']);
    NewsItem::factory()->for(NewsSource::factory()->create(['language' => 'en']), 'source')->create(['is_matched' => true]);

    $this->actingAs($user)->get('/news')->assertOk();

    Queue::assertNotPushed(TranslateNewsItemJob::class);
});

it('offers can_translate for an English item to a Turkish user', function () {
    enableChatTranslation();
    $user = User::factory()->create(['locale' => 'tr']);
    NewsItem::factory()->for(NewsSource::factory()->create(['language' => 'en']), 'source')->create(['is_matched' => true]);

    $this->actingAs($user)
        ->get('/news')
        ->assertInertia(fn (Assert $page) => $page
            ->where('news.data.0.can_translate', true)
            ->where('news.data.0.translation_status', 'original'));
});

it('translates a news item on demand and returns the translated card', function () {
    enableChatTranslation();
    fakeChatTranslations(['Çevrilmiş başlık', 'Çevrilmiş özet']);

    $user = User::factory()->create(['locale' => 'tr']);
    $news = NewsItem::factory()->for(NewsSource::factory()->create(['language' => 'en']), 'source')->create([
        'title' => 'Original title',
        'summary' => 'Original summary',
        'ai_summary' => null,
        'is_matched' => true,
    ]);

    $this->actingAs($user)
        ->postJson("/news/{$news->id}/translate")
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'title' => 'Çevrilmiş başlık',
            'summary' => 'Çevrilmiş özet',
            'translation_status' => 'translated',
            'can_translate' => false,
        ]);

    expect($news->translations()->where('locale', 'tr')->exists())->toBeTrue();
});

it('parses a reasoning-model response that wraps JSON in a think block', function () {
    enableChatTranslation();

    Http::preventStrayRequests();
    Http::fake([
        'translate.endpoints.huggingface.cloud/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => "<think>The user wants Turkish. {curly} noise…</think>\n{\"translations\":[\"Çevrildi\",\"Özet çeviri\"]}"]]],
        ]),
    ]);

    $user = User::factory()->create(['locale' => 'tr']);
    $news = NewsItem::factory()->for(NewsSource::factory()->create(['language' => 'en']), 'source')->create([
        'title' => 'Original title',
        'summary' => 'Original summary',
        'ai_summary' => null,
        'is_matched' => true,
    ]);

    $this->actingAs($user)
        ->postJson("/news/{$news->id}/translate")
        ->assertOk()
        ->assertJson(['ok' => true, 'title' => 'Çevrildi', 'summary' => 'Özet çeviri']);
});

it('translates a stock AI analysis on demand', function () {
    enableChatTranslation();
    fakeChatTranslations(['Çevrilmiş özet', 'Çevrilmiş etken', 'Çevrilmiş risk', 'Çevrilmiş uyarı']);

    $user = User::factory()->create(['locale' => 'tr']);
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);
    StockAiAnalysis::query()->create([
        'stock_id' => $stock->id,
        'signal' => StockSignal::Neutral,
        'confidence' => 50,
        'summary' => 'Summary',
        'drivers' => ['Driver'],
        'risks' => ['Risk'],
        'disclaimer' => 'Disclaimer',
        'generated_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson('/stocks/AAPL/analysis/translate')
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'analysis' => [
                'summary' => 'Çevrilmiş özet',
                'translation_status' => 'translated',
            ],
        ]);
});
