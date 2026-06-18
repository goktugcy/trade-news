<?php

declare(strict_types=1);

use App\Jobs\GenerateNewsSummaryJob;
use App\Models\NewsItem;
use App\Services\News\AiSummarizerInterface;
use App\Services\News\NullSummarizer;
use App\Services\News\OpenAiSummarizer;
use Illuminate\Support\Facades\Http;

it('stores an OpenAI summary on the news item', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Apple posted record quarterly revenue, beating estimates.']]],
        ], 200),
    ]);

    app()->instance(AiSummarizerInterface::class, new OpenAiSummarizer('test-key', 'gpt-4o-mini'));

    $item = NewsItem::factory()->create(['ai_summary' => null, 'content' => 'Apple earnings report full text.']);

    (new GenerateNewsSummaryJob([$item->id]))->handle(app(AiSummarizerInterface::class));

    expect($item->fresh()->ai_summary)->toBe('Apple posted record quarterly revenue, beating estimates.')
        ->and($item->fresh()->ai_summary_generated_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/chat/completions'));
});

it('no-ops when AI summarization is disabled', function () {
    app()->instance(AiSummarizerInterface::class, new NullSummarizer);

    $item = NewsItem::factory()->create(['ai_summary' => null]);

    (new GenerateNewsSummaryJob([$item->id]))->handle(app(AiSummarizerInterface::class));

    expect($item->fresh()->ai_summary)->toBeNull();
});

it('returns null and does not throw when the OpenAI API fails', function () {
    Http::fake(['api.openai.com/*' => Http::response('error', 500)]);

    $summary = (new OpenAiSummarizer('test-key'))->summarize(
        NewsItem::factory()->create(['content' => 'Some content']),
    );

    expect($summary)->toBeNull();
});
