<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationContact;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationContactFactory extends Factory
{
    protected $model = ApplicationContact::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'name' => fake()->name(),
            'role' => 'recruiter',
            'email' => fake()->safeEmail(),
            'phone' => null,
            'linkedin_url' => null,
            'notes' => null,
        ];
    }
}
