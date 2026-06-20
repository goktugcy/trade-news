<?php

declare(strict_types=1);

namespace App\Enums;

enum AiTask: string
{
    case Summary = 'summary';
    case SentimentTr = 'sentiment_tr';
    case SentimentEn = 'sentiment_en';
    case EntityTr = 'entity_tr';
    case EntityEn = 'entity_en';
    case Embedding = 'embedding';
    case Reranker = 'reranker';
    case StockAnalysis = 'stock_analysis';

    public function label(): string
    {
        return match ($this) {
            self::Summary => 'News summary',
            self::SentimentTr => 'Sentiment (Turkish)',
            self::SentimentEn => 'Sentiment (English)',
            self::EntityTr => 'Entity extraction (Turkish)',
            self::EntityEn => 'Entity extraction (English)',
            self::Embedding => 'Embedding / entity linking',
            self::Reranker => 'Reranking',
            self::StockAnalysis => 'Stock forecast / signal',
        };
    }

    public function defaultRuntime(): AiRuntime
    {
        return match ($this) {
            self::Summary, self::StockAnalysis => AiRuntime::OpenAiChat,
            self::SentimentTr, self::SentimentEn => AiRuntime::HfTextClassification,
            self::EntityTr, self::EntityEn => AiRuntime::HfTokenClassification,
            self::Embedding => AiRuntime::HfFeatureExtraction,
            self::Reranker => AiRuntime::HfRanking,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t): array => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
