<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Jobs\TranslateNewsItemJob;
use App\Jobs\TranslateStockAnalysisJob;
use App\Models\AiModel;
use App\Models\NewsItem;
use App\Models\NewsItemTranslation;
use App\Models\StockAiAnalysis;
use App\Models\StockAiAnalysisTranslation;
use App\Services\Ai\AiTaskService;
use Illuminate\Support\Collection;
use Throwable;

class ContentTranslationService
{
    public function __construct(
        private readonly AiTaskService $tasks,
        private readonly AiChatTranslator $aiTranslator,
        private readonly DeepLTranslator $deepLTranslator,
    ) {}

    /**
     * @param  iterable<int, NewsItem>  $items
     */
    public function queueNewsTranslations(iterable $items, string $locale): void
    {
        if (! $this->supports($locale)) {
            return;
        }

        foreach ($items as $item) {
            if (! $this->shouldTranslateNewsItem($item, $locale)) {
                continue;
            }

            TranslateNewsItemJob::dispatch($item->id, $locale);
        }
    }

    public function queueStockAnalysisTranslation(?StockAiAnalysis $analysis, string $locale): void
    {
        if ($analysis === null || ! $this->supports($locale) || $analysis->translationFor($locale) !== null) {
            return;
        }

        TranslateStockAnalysisJob::dispatch($analysis->id, $locale);
    }

    public function translateNewsItem(NewsItem $item, string $locale): ?NewsItemTranslation
    {
        $model = $this->translationModel();

        if (! $model instanceof AiModel || ! $this->shouldTranslateNewsItem($item, $locale, $model)) {
            return null;
        }

        $translator = $this->translatorFor($model);

        try {
            $result = $translator->translate($model, [
                $item->title,
                $item->ai_summary ?: $item->summary,
            ], $locale, $this->sourceLocaleForNewsItem($item));
        } catch (Throwable $exception) {
            $this->tasks->recordFailure($model, $exception->getMessage());
            report($exception);

            return null;
        }

        if ($result === null) {
            $this->tasks->recordFailure($model, 'Translation model returned no response.');

            return null;
        }

        $this->tasks->recordSuccess($model, $result->latencyMs);

        return $item->translations()->updateOrCreate(
            ['locale' => $locale],
            [
                'title' => $result->texts[0] ?? null,
                'summary' => $result->texts[1] ?? null,
                'generated_at' => now(),
                'provider' => $result->provider,
            ],
        );
    }

    public function translateStockAnalysis(StockAiAnalysis $analysis, string $locale): ?StockAiAnalysisTranslation
    {
        $model = $this->translationModel();

        if (! $model instanceof AiModel || ! $this->supports($locale, $model) || $analysis->translationFor($locale) !== null) {
            return null;
        }

        $drivers = collect($analysis->drivers ?? [])->filter()->values();
        $risks = collect($analysis->risks ?? [])->filter()->values();
        $texts = collect([$analysis->summary])
            ->merge($drivers)
            ->merge($risks)
            ->push($analysis->disclaimer ?? StockAiAnalysis::DISCLAIMER)
            ->map(fn (?string $text): ?string => $text)
            ->all();

        $translator = $this->translatorFor($model);

        try {
            $result = $translator->translate($model, $texts, $locale);
        } catch (Throwable $exception) {
            $this->tasks->recordFailure($model, $exception->getMessage());
            report($exception);

            return null;
        }

        if ($result === null) {
            $this->tasks->recordFailure($model, 'Translation model returned no response.');

            return null;
        }

        $this->tasks->recordSuccess($model, $result->latencyMs);

        $offset = 1;
        $translatedDrivers = $this->sliceTranslations($result->texts, $offset, $drivers->count());
        $offset += $drivers->count();
        $translatedRisks = $this->sliceTranslations($result->texts, $offset, $risks->count());

        return $analysis->translations()->updateOrCreate(
            ['locale' => $locale],
            [
                'summary' => $result->texts[0] ?? null,
                'drivers' => $translatedDrivers,
                'risks' => $translatedRisks,
                'disclaimer' => $result->texts[$offset + $risks->count()] ?? null,
                'generated_at' => now(),
                'provider' => $result->provider,
            ],
        );
    }

    private function shouldTranslateNewsItem(NewsItem $item, string $locale, ?AiModel $model = null): bool
    {
        if (! $this->supports($locale, $model) || $item->translationFor($locale) !== null) {
            return false;
        }

        $sourceLocale = $this->sourceLocaleForNewsItem($item);

        if ($sourceLocale !== null && $sourceLocale === $locale) {
            return false;
        }

        return trim($item->title) !== '' || trim((string) ($item->ai_summary ?: $item->summary)) !== '';
    }

    private function sourceLocaleForNewsItem(NewsItem $item): ?string
    {
        $language = $item->relationLoaded('source') ? $item->source?->language : null;

        if (! is_string($language) || $language === '') {
            return null;
        }

        return match (strtolower($language)) {
            'en', 'en-us', 'en-gb' => 'en',
            'tr', 'tr-tr' => 'tr',
            default => null,
        };
    }

    private function supports(string $locale, ?AiModel $model = null): bool
    {
        if (! in_array($locale, ['en', 'tr'], true)) {
            return false;
        }

        $model ??= $this->translationModel();

        if ($model === null) {
            return false;
        }

        return $this->translatorFor($model)->isConfigured($model);
    }

    private function translationModel(): ?AiModel
    {
        return $this->tasks->modelFor(AiTask::Translation);
    }

    private function translatorFor(AiModel $model): TextTranslatorInterface
    {
        if ($model->runtime === AiRuntime::DeepLTranslation || $model->provider->key === 'deepl') {
            return $this->deepLTranslator;
        }

        return $this->aiTranslator;
    }

    /**
     * @param  array<int, string|null>  $texts
     * @return array<int, string>
     */
    private function sliceTranslations(array $texts, int $offset, int $length): array
    {
        return Collection::make(array_slice($texts, $offset, $length))
            ->filter()
            ->values()
            ->all();
    }
}
