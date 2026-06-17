<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\Sentiment;
use App\Models\NewsItem;

/**
 * Lightweight lexicon-based sentiment + importance scorer. No external model or
 * API — fast enough to run inline in a queue job over the whole news stream.
 */
class SentimentAnalyzer
{
    /** @var array<string, float> */
    private array $lexicon = [
        // positive
        'beat' => 1, 'beats' => 1, 'record' => 1, 'surge' => 1, 'surges' => 1,
        'gain' => 0.8, 'gains' => 0.8, 'rises' => 0.8, 'rise' => 0.8, 'jump' => 1,
        'upgrade' => 1, 'upgrades' => 1, 'profit' => 0.7, 'growth' => 0.7,
        'strong' => 0.7, 'buyback' => 0.8, 'dividend' => 0.5, 'partnership' => 0.5,
        'wins' => 0.8, 'approval' => 0.7, 'outperform' => 1, 'bullish' => 1,
        // negative
        'miss' => -1, 'misses' => -1, 'slide' => -1, 'slides' => -1, 'fall' => -0.8,
        'falls' => -0.8, 'drop' => -0.8, 'drops' => -0.8, 'downgrade' => -1,
        'downgrades' => -1, 'loss' => -0.8, 'losses' => -0.8, 'weak' => -0.7,
        'weaker' => -0.7, 'lawsuit' => -0.8, 'probe' => -0.8, 'inquiry' => -0.7,
        'investigation' => -0.9, 'cut' => -0.6, 'cuts' => -0.6, 'plunge' => -1,
        'bearish' => -1, 'warning' => -0.7, 'headwinds' => -0.6, 'fraud' => -1,
    ];

    /** @var array<int, string> high-impact keywords that raise importance */
    private array $highImpact = [
        'earnings', 'guidance', 'acquisition', 'merger', 'bankruptcy', 'sec',
        'investigation', 'dividend', 'buyback', 'ipo', 'recall', 'halt', 'lawsuit',
    ];

    /**
     * @return array{sentiment: Sentiment, score: float, importance: int}
     */
    public function analyze(string $text): array
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $score = 0.0;
        $hits = 0;

        foreach ($words as $word) {
            if (isset($this->lexicon[$word])) {
                $score += $this->lexicon[$word];
                $hits++;
            }
        }

        $normalized = $hits > 0 ? max(-1.0, min(1.0, $score / max(3, $hits))) : 0.0;

        return [
            'sentiment' => Sentiment::fromScore($normalized),
            'score' => round($normalized, 4),
            'importance' => $this->importance($text, abs($normalized)),
        ];
    }

    public function applyTo(NewsItem $item): void
    {
        $result = $this->analyze(implode(' ', array_filter([$item->title, $item->summary])));

        $item->forceFill([
            'sentiment' => $result['sentiment'],
            'sentiment_score' => $result['score'],
            'importance_score' => $result['importance'],
        ])->save();
    }

    private function importance(string $text, float $sentimentStrength): int
    {
        $lower = mb_strtolower($text);
        $score = (int) round($sentimentStrength * 40); // 0-40 from sentiment strength

        foreach ($this->highImpact as $kw) {
            if (str_contains($lower, $kw)) {
                $score += 15;
            }
        }

        return max(0, min(100, $score));
    }
}
