<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendReminderEmail;
use App\Mail\ReminderDueMail;
use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendReminderEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_when_notifications_enabled(): void
    {
        Mail::fake();

        $user = User::factory()->create(['notification_email_enabled' => true]);
        $job = CanonicalJob::factory()->create();
        $app = Application::factory()->create([
            'user_id' => $user->id,
            'canonical_job_id' => $job->id,
            'status' => 'applied',
        ]);

        (new SendReminderEmail($user->id, [$app->id]))->handle();

        Mail::assertSent(ReminderDueMail::class, fn ($mail) => $mail->user->id === $user->id);
    }

    public function test_skips_when_user_opted_out(): void
    {
        Mail::fake();

        $user = User::factory()->create(['notification_email_enabled' => false]);
        $job = CanonicalJob::factory()->create();
        $app = Application::factory()->create([
            'user_id' => $user->id,
            'canonical_job_id' => $job->id,
            'status' => 'applied',
        ]);

        (new SendReminderEmail($user->id, [$app->id]))->handle();

        Mail::assertNothingSent();
    }

    public function test_skips_when_all_applications_reached_terminal(): void
    {
        Mail::fake();

        $user = User::factory()->create(['notification_email_enabled' => true]);
        $job = CanonicalJob::factory()->create();
        $app = Application::factory()->create([
            'user_id' => $user->id,
            'canonical_job_id' => $job->id,
            'status' => 'rejected',
        ]);

        (new SendReminderEmail($user->id, [$app->id]))->handle();

        Mail::assertNothingSent();
    }

    public function test_skips_when_user_missing(): void
    {
        Mail::fake();

        (new SendReminderEmail(99999, [1, 2, 3]))->handle();

        Mail::assertNothingSent();
    }
}
