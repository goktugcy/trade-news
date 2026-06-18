<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Alerts\AlertEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Evaluates all active user stock alerts against the latest quotes/news.
 */
class EvaluateAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(AlertEvaluator $evaluator): void
    {
        $evaluator->evaluateAll();
    }
}
