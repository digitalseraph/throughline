<?php

namespace App\Console\Commands;

use App\Jobs\ApplicationReminderScan;
use Illuminate\Console\Command;

class ReminderScanNowCommand extends Command
{
    protected $signature = 'throughline:reminder-scan-now';

    protected $description = 'Run ApplicationReminderScan synchronously for debugging.';

    public function handle(): int
    {
        (new ApplicationReminderScan)->handle();
        $this->info('Reminder scan complete.');

        return self::SUCCESS;
    }
}
