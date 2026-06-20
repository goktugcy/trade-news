<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\AiModel;

interface TextTranslatorInterface
{
    public function isConfigured(AiModel $model): bool;

    /**
     * @param  array<int, string|null>  $texts
     */
    public function translate(AiModel $model, array $texts, string $targetLocale, ?string $sourceLocale = null): ?TranslationResult;
}
