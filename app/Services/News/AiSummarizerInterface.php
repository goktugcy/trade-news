<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\NewsItem;

interface AiSummarizerInterface
{
    /**
     * Produce a short neutral summary for an article, or null when AI
     * summarization is unavailable / fails (caller keeps the original summary).
     */
    public function summarize(NewsItem $item): ?string;

    public function isEnabled(): bool;
}
