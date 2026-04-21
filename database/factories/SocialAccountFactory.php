<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => fake()->randomElement(['google', 'github']),
            'provider_user_id' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
        ];
    }
}
