<?php

namespace App\Jobs;

use App\Models\Application;
use App\Services\Applications\EventWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplicationReminderScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $writer = app(EventWriter::class);
        $nonTerminal = collect(Application::STATUSES)->diff(Application::TERMINAL_STATUSES)->values()->all();

        Application::whereIn('status', $nonTerminal)
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', now())
            ->chunkById(100, function ($apps) use ($writer) {
                foreach ($apps as $app) {
                    if ($this->alreadyRemindedToday($app)) {
                        continue;
                    }
                    $writer->logReminder($app);
                }
            });
    }

    protected function alreadyRemindedToday(Application $app): bool
    {
        return $app->events()
            ->where('type', 'reminder')
            ->whereDate('occurred_at', now()->toDateString())
            ->exists();
    }
}
