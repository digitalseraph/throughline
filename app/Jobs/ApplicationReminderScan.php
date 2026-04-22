<?php

namespace App\Jobs;

use App\Events\ReminderBadgeUpdated;
use App\Models\Application;
use App\Models\User;
use App\Services\Applications\EventWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplicationReminderScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $writer = app(EventWriter::class);
        $nonTerminal = collect(Application::STATUSES)->diff(Application::TERMINAL_STATUSES)->values()->all();

        /** @var array<int, list<int>> $byUser  user_id => [app_id, ...] */
        $byUser = [];

        Application::whereIn('status', $nonTerminal)
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', now())
            ->chunkById(100, function ($apps) use ($writer, &$byUser) {
                foreach ($apps as $app) {
                    if ($this->alreadyRemindedToday($app)) {
                        continue;
                    }
                    $writer->logReminder($app);
                    $byUser[$app->user_id][] = $app->id;
                }
            });

        foreach ($byUser as $userId => $appIds) {
            // Broadcast badge update.
            try {
                $count = Application::where('user_id', $userId)
                    ->whereNotIn('status', Application::TERMINAL_STATUSES)
                    ->whereNotNull('next_action_at')
                    ->where('next_action_at', '<=', now())
                    ->count();

                ReminderBadgeUpdated::dispatch($userId, $count);
            } catch (\Throwable $e) {
                Log::warning('ReminderBadgeUpdated broadcast failed in scan', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Dispatch email job (race-safe re-check inside handler).
            $user = User::find($userId);
            if ($user && $user->notification_email_enabled) {
                SendReminderEmail::dispatch($userId, $appIds);
            }
        }
    }

    protected function alreadyRemindedToday(Application $app): bool
    {
        return $app->events()
            ->where('type', 'reminder')
            ->whereDate('occurred_at', now()->toDateString())
            ->exists();
    }
}
