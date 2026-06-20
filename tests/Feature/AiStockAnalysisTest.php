<?php

declare(strict_types=1);

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Enums\ProviderType;
use App\Enums\StockSignal;
use App\Jobs\GenerateStockAnalysisJob;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\AiTaskSetting;
use App\Models\ApiProvider;
use App\Models\Stock;
use App\Models\StockAiAnalysis;
use App\Models\User;
use App\Services\Ai\StockAnalyzer;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function enableStockAnalysis(string $endpoint = 'https://analysis.hf.cloud'): AiModel
{
    AiSetting::query()->create(['enabled' => true]);

    $provider = ApiProvider::factory()->create([
        'key' => 'huggingface',
        'name' => 'Hugging Face',
        'type' => ProviderType::Ai,
        'base_url' => null,
        'api_key' => 'hf-secret',
        'is_active' => true,
    ]);

    $model = AiModel::factory()->for($provider, 'provider')->create([
        'name' => 'HF analysis',
        'model' => 'analysis-model',
        'task' => AiTask::StockAnalysis,
        'runtime' => AiRuntime::OpenAiChat,
        'endpoint_url' => $endpoint,
        'is_active' => true,
        'max_output_tokens' => 700,
    ]);

    AiTaskSetting::query()->create([
        'task' => AiTask::StockAnalysis->value,
        'enabled' => true,
        'active_ai_model_id' => $model->id,
    ]);

    return $model;
}

it('generates and stores a parsed AI stock analysis', function () {
    $model = enableStockAnalysis();
    $stock = Stock::factory()->create(['symbol' => 'AAPL', 'currency' => 'USD']);

    $json = json_encode([
        'signal' => 'bullish',
        'confidence' => 72,
        'horizon' => '1-3 months',
        'estimated_price_low' => 180.5,
        'estimated_price_high' => 210,
        'estimated_price' => 195,
        'summary' => 'Momentum is positive.',
        'drivers' => ['Strong earnings', 'Sector tailwinds'],
        'risks' => ['Macro headwinds'],
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'analysis.hf.cloud/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => "```json\n{$json}\n```"]]],
        ], 200),
    ]);

    (new GenerateStockAnalysisJob([$stock->id]))->handle(app(StockAnalyzer::class));

    $analysis = StockAiAnalysis::query()->where('stock_id', $stock->id)->firstOrFail();

    expect($analysis->signal)->toBe(StockSignal::Bullish)
        ->and($analysis->confidence)->toBe(72)
        ->and($analysis->estimated_price)->toBe(195.0)
        ->and($analysis->estimated_price_low)->toBe(180.5)
        ->and($analysis->drivers)->toBe(['Strong earnings', 'Sector tailwinds'])
        ->and($analysis->risks)->toBe(['Macro headwinds'])
        ->and($analysis->ai_model_id)->toBe($model->id)
        ->and($analysis->disclaimer)->toBe(StockAiAnalysis::DISCLAIMER)
        ->and($analysis->generated_at)->not->toBeNull();
});

it('does not generate analysis when the task is disabled', function () {
    $stock = Stock::factory()->create();

    Http::preventStrayRequests();
    Http::fake();

    (new GenerateStockAnalysisJob([$stock->id]))->handle(app(StockAnalyzer::class));

    expect(StockAiAnalysis::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('renders the analysis card with disclaimer, signal and price range on the stock page', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'MSFT', 'currency' => 'USD']);

    StockAiAnalysis::query()->create([
        'stock_id' => $stock->id,
        'signal' => StockSignal::Bullish,
        'confidence' => 65,
        'horizon' => '1-3 months',
        'estimated_price_low' => 300,
        'estimated_price_high' => 340,
        'estimated_price' => 320,
        'currency' => 'USD',
        'summary' => 'Solid outlook.',
        'drivers' => ['Cloud growth'],
        'risks' => ['Regulation'],
        'disclaimer' => StockAiAnalysis::DISCLAIMER,
        'generated_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($user)
        ->get('/stocks/MSFT')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('stocks/Show')
            ->where('analysis.signal', 'bullish')
            ->where('analysis.confidence', 65)
            ->where('analysis.estimated_price_low', 300)
            ->where('analysis.estimated_price_high', 340)
            ->where('analysis.disclaimer', StockAiAnalysis::DISCLAIMER));
});

it('flags a stale analysis when it is expired', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create(['symbol' => 'TSLA', 'currency' => 'USD']);

    StockAiAnalysis::query()->create([
        'stock_id' => $stock->id,
        'signal' => StockSignal::Bearish,
        'confidence' => 40,
        'currency' => 'USD',
        'summary' => 'Old read.',
        'disclaimer' => StockAiAnalysis::DISCLAIMER,
        'generated_at' => now()->subDays(3),
        'expires_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->get('/stocks/TSLA')
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.is_stale', true)
            ->where('analysis.signal', 'bearish'));
});

it('shows no analysis when none exists', function () {
    $user = User::factory()->create();
    Stock::factory()->create(['symbol' => 'NVDA']);

    $this->actingAs($user)
        ->get('/stocks/NVDA')
        ->assertInertia(fn (Assert $page) => $page->where('analysis', null));
});
