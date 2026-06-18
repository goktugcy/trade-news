<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SystemJob;
use App\Services\Alerts\AlertEvaluator;
use Illuminate\Console\Command;

class EvaluateAlertsCommand extends Command
{
    protected $signature = 'tradenews:evaluate-alerts';

    protected $description = 'Evaluate user stock alerts (price/volume/news) and fire notifications';

    public function handle(AlertEvaluator $evaluator): int
    {
        $fired = SystemJob::track('tradenews:evaluate-alerts', function (SystemJob $job) use ($evaluator): int {
            $count = $evaluator->evaluateAll();
            $job->update(['meta' => ['fired' => $count]]);

            return $count;
        });

        $this->info("Fired {$fired} alert(s).");

        return self::SUCCESS;
    }
}
