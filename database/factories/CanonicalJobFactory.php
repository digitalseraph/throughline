<?php

namespace Database\Factories;

use App\Models\CanonicalJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class CanonicalJobFactory extends Factory
{
    protected $model = CanonicalJob::class;

    public function definition(): array
    {
        return [
            'source' => 'greenhouse',
            'source_id' => fake()->unique()->numerify('########'),
            'source_board' => 'stripe',
            'company_name' => 'Stripe',
            'title' => fake()->jobTitle(),
            'description' => fake()->paragraph(),
            'location_raw' => 'San Francisco, CA',
            'locations' => ['San Francisco'],
            'remote_mode' => 'onsite',
            'apply_url' => fake()->url(),
            'posted_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
