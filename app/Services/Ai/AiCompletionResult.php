<?php

declare(strict_types=1);

namespace App\Services\Ai;

final readonly class AiCompletionResult
{
    /**
     * @param  array<string, mixed>|null  $json  decoded JSON payload (chat/structured output)
     * @param  array<int, array{label: string, score: float}>|null  $scores  classification scores
     * @param  array<int, array<string, mixed>>|null  $entities  token-classification entities
     * @param  array<int, float>|null  $embedding  feature-extraction embedding vector
     */
    public function __construct(
        public bool $successful,
        public ?string $text = null,
        public ?int $latencyMs = null,
        public ?string $error = null,
        public ?array $json = null,
        public ?array $scores = null,
        public ?array $entities = null,
        public ?array $embedding = null,
    ) {}
}
