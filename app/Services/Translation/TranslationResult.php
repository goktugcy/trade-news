<?php

declare(strict_types=1);

namespace App\Services\Translation;

final readonly class TranslationResult
{
    /**
     * @param  array<int, string|null>  $texts
     */
    public function __construct(
        public array $texts,
        public ?string $detectedSourceLanguage,
        public string $provider = 'deepl',
        public ?int $latencyMs = null,
    ) {}
}
