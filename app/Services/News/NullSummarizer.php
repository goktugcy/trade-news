<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\NewsItem;

/**
 * No-op summarizer used when no AI key is configured. The feed simply shows the
 * article's own summary.
 */
class NullSummarizer implements AiSummarizerInterface
{
    public function summarize(NewsItem $item): ?string
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
