<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiTask;
use App\Enums\StockSignal;
use App\Models\Stock;
use App\Models\StockAiAnalysis;
use App\Support\AiTextQuality;
use App\Support\Presenters\StockPresenter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates a cached AI forecast/signal for a stock. NEVER call from an HTTP
 * request — run it from GenerateStockAnalysisJob / the scheduled command.
 */
class StockAnalyzer
{
    public function __construct(private readonly AiTaskService $tasks) {}

    public function isEnabled(): bool
    {
        return $this->tasks->modelFor(AiTask::StockAnalysis) !== null;
    }

    public function analyze(Stock $stock): ?StockAiAnalysis
    {
        $model = $this->tasks->modelFor(AiTask::StockAnalysis);
        $client = $model !== null ? $this->tasks->clientFor($model) : null;

        if ($model === null || $client === null) {
            return null;
        }

        $snapshot = $this->snapshot($stock);

        $result = $client->complete($model, $this->prompt($snapshot), $this->instructions());

        if (! $result->successful || $result->text === null) {
            $this->tasks->recordFailure($model, $result->error, $result->latencyMs);
            Log::warning('Stock analysis failed', ['stock' => $stock->id, 'error' => $result->error]);

            return null;
        }

        $parsed = $this->parse($result->text);

        if ($parsed === null) {
            $this->tasks->recordFailure($model, 'Unparseable AI response.', $result->latencyMs);

            return null;
        }

        $summary = AiTextQuality::completeParagraph(isset($parsed['summary']) ? (string) $parsed['summary'] : null, maxCharacters: 900);

        if ($summary === null) {
            $this->tasks->recordFailure($model, 'Incomplete AI stock analysis summary.', $result->latencyMs);

            return null;
        }

        $this->tasks->recordSuccess($model, $result->latencyMs);

        return StockAiAnalysis::query()->create([
            'stock_id' => $stock->id,
            'ai_model_id' => $model->id,
            'signal' => StockSignal::fromLoose($parsed['signal'] ?? null),
            'confidence' => max(0, min(100, (int) round((float) ($parsed['confidence'] ?? 0)))),
            'horizon' => isset($parsed['horizon']) ? (string) Str::limit((string) $parsed['horizon'], 32, '') : null,
            'estimated_price_low' => $this->floatOrNull($parsed['estimated_price_low'] ?? null),
            'estimated_price_high' => $this->floatOrNull($parsed['estimated_price_high'] ?? null),
            'estimated_price' => $this->floatOrNull($parsed['estimated_price'] ?? null),
            'currency' => $stock->currency,
            'summary' => $summary,
            'drivers' => $this->stringList($parsed['drivers'] ?? null),
            'risks' => $this->stringList($parsed['risks'] ?? null),
            'disclaimer' => StockAiAnalysis::DISCLAIMER,
            'input_snapshot' => $snapshot,
            'generated_at' => now(),
            'expires_at' => now()->addHours((int) config('tradenews.ai.stock_analysis_ttl_hours', 24)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Stock $stock): array
    {
        $quote = StockPresenter::quote($stock->loadMissing('latestPrice'));

        $headlines = $stock->news()
            ->where('is_matched', true)
            ->orderByDesc('published_at')
            ->limit(6)
            ->get(['news_items.id', 'title', 'sentiment', 'published_at'])
            ->map(fn ($n): array => [
                'title' => Str::limit((string) $n->title, 180, ''),
                'sentiment' => $n->sentiment?->value,
                'published_at' => $n->published_at?->toDateString(),
            ])
            ->all();

        return [
            'symbol' => $stock->symbol,
            'name' => $stock->name,
            'market' => $stock->market->value,
            'currency' => $stock->currency,
            'sector' => $stock->sector,
            'price' => $quote['price'] ?? null,
            'change_percent' => $quote['change_percent'] ?? null,
            'as_of' => $quote['at'] ?? null,
            'headlines' => $headlines,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function prompt(array $snapshot): string
    {
        return (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
        You are a financial analysis assistant. Given a compact JSON snapshot of a stock
        (price, recent change, sector, and recent news headlines with sentiment),
        respond with ONLY a compact JSON object (no markdown, no prose) with these keys:
        {
          "signal": "bullish" | "neutral" | "bearish",
          "confidence": 0-100 integer,
          "horizon": short text like "1-3 months",
          "estimated_price_low": number,
          "estimated_price_high": number,
          "estimated_price": number,
          "summary": "one paragraph, exactly 2 complete neutral sentences, 45-80 words total",
          "drivers": ["short concrete factor, 6-14 words", "short concrete factor, 6-14 words"],
          "risks": ["short concrete risk, 6-14 words", "short concrete risk, 6-14 words"]
        }
        Complete every sentence. Do not use ellipses. Do not end any text with "and", "or", "because", "with", or another dangling connector.
        Base prices on the provided current price. Do not give investment advice.
        PROMPT;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(string $text): ?array
    {
        $text = trim($text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<int, string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $items = collect($value)
            ->map(fn (mixed $v): ?string => is_scalar($v) ? AiTextQuality::completeListItem((string) $v) : null)
            ->filter()
            ->take(2)
            ->values()
            ->all();

        return $items === [] ? null : $items;
    }
}
