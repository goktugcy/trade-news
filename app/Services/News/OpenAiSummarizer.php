<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\NewsItem;
use App\Support\AiTextQuality;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates a concise, neutral financial summary via the OpenAI Chat
 * Completions API (using the Laravel HTTP client — no SDK). Returns null on any
 * failure so the pipeline degrades gracefully to the original summary.
 */
class OpenAiSummarizer implements AiSummarizerInterface
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = 'gpt-4o-mini',
        private readonly string $baseUrl = 'https://api.openai.com/v1',
    ) {}

    public function isEnabled(): bool
    {
        return ! empty($this->apiKey);
    }

    public function summarize(NewsItem $item): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $source = trim($item->title."\n\n".Str::limit((string) ($item->content ?: $item->summary), 4000));

        if ($source === '') {
            return null;
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken((string) $this->apiKey)
                ->timeout(20)
                ->retry(2, 500)
                ->post('/chat/completions', [
                    'model' => $this->model,
                    'temperature' => 0.3,
                    'max_tokens' => 220,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You summarize financial news in exactly one short paragraph with 2 complete neutral, factual sentences. '
                                .'Keep it 45-80 words total. No opinions, no advice, no hype. Do not use ellipses. '
                                .'Do not end with a conjunction. Plain text only.',
                        ],
                        ['role' => 'user', 'content' => $source],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('OpenAI summary failed', ['news_item' => $item->id, 'status' => $response->status()]);

                return null;
            }

            $summary = AiTextQuality::completeParagraph($response->json('choices.0.message.content'), maxCharacters: 700);

            return $summary;
        } catch (\Throwable $e) {
            Log::warning('OpenAI summary error', ['news_item' => $item->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
