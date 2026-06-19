<?php

declare(strict_types=1);

namespace App\Services\Ai;

final readonly class AiCompletionResult
{
    public function __construct(
        public bool $successful,
        public ?string $text = null,
        public ?int $latencyMs = null,
        public ?string $error = null,
    ) {}
}
