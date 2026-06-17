<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SystemJob;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Console\Command;

class DispatchNotificationsCommand extends Command
{
    protected $signature = 'tradenews:dispatch-notifications';

    protected $description = 'Queue Telegram alerts for notification rules due at this minute';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $queued = SystemJob::track('tradenews:dispatch-notifications', function (SystemJob $job) use ($dispatcher): int {
            $count = $dispatcher->dispatchDue(now());
            $job->update(['meta' => ['queued' => $count]]);

            return $count;
        });

        $this->info("Queued {$queued} alert(s).");

        return self::SUCCESS;
    }
}
