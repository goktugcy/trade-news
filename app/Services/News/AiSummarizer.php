<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\AiModel;
use App\Models\NewsItem;
use App\Services\Ai\AiProviderClientInterface;
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
            'You summarize financial news in 2-3 neutral, factual sentences. No opinions, no advice, no hype. Plain text only.',
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

        $summary = trim((string) $result->text);

        return $summary !== '' ? $summary : null;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
