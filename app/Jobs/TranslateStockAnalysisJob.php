<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StockAiAnalysis;
use App\Services\Translation\ContentTranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TranslateStockAnalysisJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $stockAiAnalysisId,
        public readonly string $locale,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ContentTranslationService $translations): void
    {
        $analysis = StockAiAnalysis::query()
            ->with(['translations' => fn ($query) => $query->where('locale', $this->locale)])
            ->find($this->stockAiAnalysisId);

        if ($analysis === null) {
            return;
        }

        $translations->translateStockAnalysis($analysis, $this->locale);
    }
}
