<?php

namespace Tests\Feature\Mail;

use App\Mail\ReminderDueMail;
use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderDueMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_with_seeded_applications(): void
    {
        $user = User::factory()->create();
        $job = CanonicalJob::factory()->create(['title' => 'Software Engineer', 'company_name' => 'Acme Corp']);
        $app = Application::factory()->create([
            'user_id' => $user->id,
            'canonical_job_id' => $job->id,
            'status' => 'applied',
        ]);

        $mailable = new ReminderDueMail($user, [$app->id]);

        $mailable->assertHasSubject('Applications due for follow-up');
        $mailable->assertTo($user->email);

        $html = $mailable->render();
        $this->assertStringContainsString('Software Engineer', $html);
        $this->assertStringContainsString('Acme Corp', $html);
    }

    public function test_pluralises_correctly(): void
    {
        $user = User::factory()->create();
        [$job1, $job2] = CanonicalJob::factory()->count(2)->create();
        $app1 = Application::factory()->create(['user_id' => $user->id, 'canonical_job_id' => $job1->id]);
        $app2 = Application::factory()->create(['user_id' => $user->id, 'canonical_job_id' => $job2->id]);

        $html = (new ReminderDueMail($user, [$app1->id, $app2->id]))->render();
        $this->assertStringContainsString('2 applications', $html);
    }
}
