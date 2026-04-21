<?php

namespace Database\Factories;

use App\Models\JobSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class JobSourceFactory extends Factory
{
    protected $model = JobSource::class;

    public function definition(): array
    {
        return [
            'source_type' => 'greenhouse',
            'identifier' => fake()->slug(1),
            'display_name' => fake()->company(),
            'is_enabled' => true,
            'fetch_cadence_minutes' => 60,
        ];
    }
}
