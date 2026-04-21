<?php

namespace Tests\Feature\Resumes;

use App\Jobs\ParseResume;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResumeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_dispatches_parse_and_marks_first_as_default(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $this->actingAs($user)
            ->post(route('resumes.store'), ['name' => 'Staff frontend', 'file' => $file])
            ->assertRedirect();

        $resume = Resume::first();
        $this->assertTrue($resume->is_default);
        Queue::assertPushed(ParseResume::class);
    }

    public function test_second_resume_does_not_become_default(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        Resume::factory()->for($user)->default()->create();
        $file = UploadedFile::fake()->create('other.pdf', 100, 'application/pdf');

        $this->actingAs($user)->post(route('resumes.store'), ['name' => 'Eng manager', 'file' => $file]);

        $this->assertSame(1, Resume::where('is_default', true)->count());
    }

    public function test_set_default_flips_exclusivity(): void
    {
        $user = User::factory()->create();
        $current = Resume::factory()->for($user)->default()->create();
        $next = Resume::factory()->for($user)->create();

        $this->actingAs($user)->post(route('resumes.default', $next))->assertRedirect();

        $this->assertTrue($next->fresh()->is_default);
        $this->assertFalse($current->fresh()->is_default);
    }

    public function test_show_forbids_other_users_resumes(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $resume = Resume::factory()->for($owner)->create();

        $this->actingAs($stranger)->get(route('resumes.show', $resume))->assertForbidden();
    }

    public function test_destroy_removes_file_and_row(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        Storage::disk('local')->put('resumes/stub.pdf', 'x');
        $resume = Resume::factory()->for($user)->create(['source_file_path' => 'resumes/stub.pdf']);

        $this->actingAs($user)->delete(route('resumes.destroy', $resume))->assertRedirect();

        $this->assertDatabaseMissing('resumes', ['id' => $resume->id]);
        Storage::disk('local')->assertMissing('resumes/stub.pdf');
    }
}
