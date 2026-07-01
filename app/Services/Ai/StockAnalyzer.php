<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiTask;
use App\Enums\StockSignal;
use App\Enums\Timeframe;
use App\Models\Stock;
use App\Models\StockAiAnalysis;
use App\Services\Market\MarketSessionService;
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
            // "outlook" is the new key; fall back to legacy "signal" for older prompts.
            'signal' => StockSignal::fromLoose($parsed['outlook'] ?? $parsed['signal'] ?? null),
            'confidence' => max(0, min(100, (int) round((float) ($parsed['confidence'] ?? 0)))),
            'horizon' => isset($parsed['horizon']) ? (string) Str::limit((string) $parsed['horizon'], 32, '') : null,
            // Price targets are intentionally not generated or shown (kept null).
            'estimated_price_low' => null,
            'estimated_price_high' => null,
            'estimated_price' => null,
            'currency' => $stock->currency,
            'summary' => $summary,
            // "opportunities" is the new key; fall back to legacy "drivers".
            'drivers' => $this->stringList($parsed['opportunities'] ?? $parsed['drivers'] ?? null),
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

        // Backend-available context only — never fabricate technical indicators.
        // Optional fields are omitted when the underlying data is missing.
        $snapshot = [
            'symbol' => $stock->symbol,
            'name' => $stock->name,
            'market' => $stock->market->value,
            'currency' => $stock->currency,
            'sector' => $stock->sector,
            'price' => $quote['price'] ?? null,
            'change_percent_1d' => $quote['change_percent'] ?? null,
            'as_of' => $quote['at'] ?? null,
            'market_session' => app(MarketSessionService::class)->session($stock->market)['session'] ?? null,
            'headlines' => $headlines,
        ];

        if (isset($quote['volume'])) {
            $snapshot['volume'] = $quote['volume'];
        }

        if (isset($quote['average_volume'])) {
            $snapshot['average_volume'] = $quote['average_volume'];
        }

        $changes = $this->multiDayChanges($stock);

        foreach ($changes as $key => $value) {
            $snapshot[$key] = $value;
        }

        $importance = (int) $stock->news()
            ->where('is_matched', true)
            ->max('importance_score');

        if ($importance > 0) {
            $snapshot['max_news_importance'] = $importance;
        }

        $followers = $stock->watchers()->count();

        if ($followers > 0) {
            $snapshot['watchlist_followers'] = $followers;
        }

        return $snapshot;
    }

    /**
     * 5-day and 20-day percent change computed from stored daily candles, only
     * when enough history exists. Returns an empty array otherwise (no faking).
     *
     * @return array<string, float>
     */
    private function multiDayChanges(Stock $stock): array
    {
        $closes = $stock->prices()
            ->where('timeframe', Timeframe::OneDay->value)
            ->orderByDesc('price_at')
            ->limit(21)
            ->pluck('close')
            ->map(fn ($c): float => (float) $c)
            ->all();

        $latest = $closes[0] ?? null;
        $changes = [];

        if ($latest === null) {
            return $changes;
        }

        foreach (['change_percent_5d' => 5, 'change_percent_20d' => 20] as $key => $sessions) {
            $past = $closes[$sessions] ?? null;

            if ($past !== null && $past != 0.0) {
                $changes[$key] = round((($latest - $past) / $past) * 100, 2);
            }
        }

        return $changes;
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
        You are a financial analysis assistant producing a neutral "AI Outlook" for a stock.
        You are given a compact JSON snapshot (price, recent changes, volume, market session,
        recent news headlines with sentiment, importance and watchlist interest). Respond with
        ONLY a compact JSON object (no markdown, no prose) with these keys:
        {
          "outlook": "positive" | "neutral" | "negative",
          "confidence": 0-100 integer,
          "horizon": short text like "1-3 months",
          "summary": "one paragraph, exactly 2 complete neutral sentences, 45-80 words, covering the technical, news and sentiment context",
          "opportunities": ["short concrete opportunity, 6-14 words", "short concrete opportunity, 6-14 words"],
          "risks": ["short concrete risk, 6-14 words", "short concrete risk, 6-14 words"]
        }
        Only use the data provided. Do NOT invent technical indicators (no RSI, MACD, moving averages) unless present in the snapshot.
        Do NOT give a price target or estimated price. Do NOT use the words "buy", "sell", or recommendation language. This is not investment advice.
        Complete every sentence. Do not use ellipses. Do not end any text with "and", "or", "because", "with", or another dangling connector.
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
