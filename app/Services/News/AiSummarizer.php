<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\AiModel;
use App\Models\NewsItem;
use App\Services\Ai\AiProviderClientInterface;
use App\Support\AiTextQuality;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiSummarizer implements AiSummarizerInterface
{
    public function __construct(
        private readonly AiProviderClientInterface $client,
        private readonly AiModel $model,
    ) {}

    public function summarize(NewsItem $item): ?string
    {
        $source = trim($item->title."\n\n".Str::limit((string) ($item->content ?: $item->summary), 4000));

        if ($source === '') {
            return null;
        }

        $result = $this->client->complete(
            $this->model,
            $source,
            'You summarize financial news in exactly one short paragraph with 2 complete neutral, factual sentences. Keep it 45-80 words total. No opinions, no advice, no hype. Do not use ellipses. Do not end with a conjunction. Plain text only.',
        );

        if (! $result->successful) {
            Log::warning('AI summary failed', [
                'news_item' => $item->id,
                'provider' => $this->model->provider->key,
                'model' => $this->model->model,
                'error' => $result->error,
            ]);

            return null;
        }

        $summary = AiTextQuality::completeParagraph($result->text, maxCharacters: 700);

        return $summary;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
