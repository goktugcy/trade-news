<?php

declare(strict_types=1);

namespace App\Services\Ai\Concerns;

trait MeasuresAiRequests
{
    private function startedAt(): float
    {
        return microtime(true);
    }

    private function latencyMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
