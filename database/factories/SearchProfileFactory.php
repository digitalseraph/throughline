<?php

namespace Database\Factories;

use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SearchProfileFactory extends Factory
{
    protected $model = SearchProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'resume_id' => null,
            'name' => fake()->jobTitle().' search',
            'role_keywords' => ['engineer'],
            'locations' => [],
            'remote_mode' => 'any',
            'comp_min' => null,
            'comp_currency' => null,
            'industries' => [],
            'seniority_levels' => [],
            'excluded_companies' => [],
            'is_active' => true,
        ];
    }
}
