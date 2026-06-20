<?php

declare(strict_types=1);

namespace App\Enums;

enum AiRuntime: string
{
    case OpenAiChat = 'openai_chat';
    case HfTextClassification = 'hf_text_classification';
    case HfTokenClassification = 'hf_token_classification';
    case HfFeatureExtraction = 'hf_feature_extraction';
    case HfRanking = 'hf_ranking';
    case HfSummarization = 'hf_summarization';
    case DeepLTranslation = 'deepl_translation';

    public function label(): string
    {
        return match ($this) {
            self::OpenAiChat => 'Chat completions (OpenAI-compatible)',
            self::HfTextClassification => 'HF text classification',
            self::HfTokenClassification => 'HF token classification (NER)',
            self::HfFeatureExtraction => 'HF feature extraction (embeddings)',
            self::HfRanking => 'HF ranking / reranker',
            self::HfSummarization => 'HF summarization',
            self::DeepLTranslation => 'DeepL translation API',
        };
    }

    public function isChat(): bool
    {
        return $this === self::OpenAiChat;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $r): array => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
