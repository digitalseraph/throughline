<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'canonical_job_id' => CanonicalJob::factory(),
            'search_profile_id' => null,
            'resume_id' => null,
            'status' => 'saved',
            'applied_at' => null,
            'next_action_at' => null,
            'notes' => null,
            'stage2_snapshot' => null,
        ];
    }

    public function applied(): self
    {
        return $this->state(['status' => 'applied', 'applied_at' => now()]);
    }
}
