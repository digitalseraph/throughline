<?php

namespace App\Jobs;

use App\Mail\ReminderDueMail;
use App\Models\Application;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendReminderEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $userId, public array $applicationIds) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user || !$user->notification_email_enabled) {
            return;
        }

        // Race check: applications may have been updated (e.g., status transitioned) between scan and send.
        $stillDue = Application::whereIn('id', $this->applicationIds)
            ->whereNotIn('status', Application::TERMINAL_STATUSES)
            ->pluck('id')->all();
        if (empty($stillDue)) {
            return;
        }

        Mail::send(new ReminderDueMail($user, $stillDue));
    }
}
