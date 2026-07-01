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
        // AI Outlook never produces price targets — these stay null.
        ->and($analysis->estimated_price)->toBeNull()
        ->and($analysis->estimated_price_low)->toBeNull()
        ->and($analysis->estimated_price_high)->toBeNull()
        ->and($analysis->drivers)->toBe(['Strong earnings', 'Sector tailwinds'])
        ->and($analysis->risks)->toBe(['Macro headwinds'])
        ->and($analysis->ai_model_id)->toBe($model->id)
        ->and($analysis->disclaimer)->toBe(StockAiAnalysis::DISCLAIMER)
        ->and($analysis->generated_at)->not->toBeNull();

    Http::assertSent(fn ($request): bool => $request->data()['max_tokens'] === 700
        && str_contains((string) data_get($request->data(), 'messages.0.content'), 'exactly 2 complete neutral sentences')
        && str_contains((string) data_get($request->data(), 'messages.0.content'), 'Do not use ellipses'));
});

it('does not store a stock analysis when the generated summary is incomplete', function () {
    $model = enableStockAnalysis();
    $stock = Stock::factory()->create(['symbol' => 'AAPL', 'currency' => 'USD']);

    $json = json_encode([
        'signal' => 'neutral',
        'confidence' => 51,
        'horizon' => '1-3 months',
        'estimated_price_low' => 180,
        'estimated_price_high' => 195,
        'estimated_price' => 188,
        'summary' => 'Momentum is improving as recent headlines support sentiment and',
        'drivers' => ['Recent sentiment improved'],
        'risks' => ['Valuation remains stretched'],
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'analysis.hf.cloud/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => (string) $json]]],
        ], 200),
    ]);

    (new GenerateStockAnalysisJob([$stock->id]))->handle(app(StockAnalyzer::class));

    expect(StockAiAnalysis::query()->where('stock_id', $stock->id)->count())->toBe(0)
        ->and($model->fresh()->last_error)->toBe('Incomplete AI stock analysis summary.');
});

it('does not generate analysis when the task is disabled', function () {
    $stock = Stock::factory()->create();

    Http::preventStrayRequests();
    Http::fake();

    (new GenerateStockAnalysisJob([$stock->id]))->handle(app(StockAnalyzer::class));

    expect(StockAiAnalysis::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('renders the AI Outlook card without any price target on the stock page', function () {
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
            ->where('analysis.signal_label', 'Positive')
            ->where('analysis.confidence', 65)
            ->missing('analysis.estimated_price')
            ->missing('analysis.estimated_price_low')
            ->missing('analysis.estimated_price_high')
            ->where('analysis.disclaimer', StockAiAnalysis::DISCLAIMER));
});

it('maps the new outlook + opportunities keys and enriches the snapshot', function () {
    $model = enableStockAnalysis();
    $stock = Stock::factory()->create(['symbol' => 'NVDA', 'currency' => 'USD']);

    // A daily candle history so 5-day change is computable (not faked).
    foreach (range(0, 6) as $i) {
        $stock->prices()->create([
            'timeframe' => '1d',
            'open' => 100 + $i,
            'high' => 101 + $i,
            'low' => 99 + $i,
            'close' => 100 + $i,
            'volume' => 1000,
            'price_at' => now()->subDays($i),
        ]);
    }

    $json = json_encode([
        'outlook' => 'positive',
        'confidence' => 60,
        'horizon' => '1-3 months',
        'summary' => 'Technical momentum is steady. Recent news sentiment stays supportive.',
        'opportunities' => ['Datacenter demand expanding', 'Margins holding firm'],
        'risks' => ['Valuation is rich'],
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'analysis.hf.cloud/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => (string) $json]]],
        ], 200),
    ]);

    (new GenerateStockAnalysisJob([$stock->id]))->handle(app(StockAnalyzer::class));

    $analysis = StockAiAnalysis::query()->where('stock_id', $stock->id)->firstOrFail();

    expect($analysis->signal)->toBe(StockSignal::Bullish)
        ->and($analysis->signal->label())->toBe('Positive')
        ->and($analysis->drivers)->toBe(['Datacenter demand expanding', 'Margins holding firm'])
        ->and($analysis->estimated_price)->toBeNull()
        ->and($analysis->input_snapshot)->toHaveKey('market_session')
        ->and($analysis->input_snapshot)->toHaveKey('change_percent_5d');

    // The instructions forbid price targets and buy/sell language.
    Http::assertSent(fn ($request): bool => str_contains((string) data_get($request->data(), 'messages.0.content'), '"outlook"')
        && str_contains((string) data_get($request->data(), 'messages.0.content'), 'Do NOT give a price target'));
});

it('exposes Positive/Neutral/Negative outlook labels', function () {
    expect(StockSignal::Bullish->label())->toBe('Positive')
        ->and(StockSignal::Neutral->label())->toBe('Neutral')
        ->and(StockSignal::Bearish->label())->toBe('Negative');
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
