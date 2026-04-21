<?php

namespace Database\Factories;

use App\Models\Resume;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResumeFactory extends Factory
{
    protected $model = Resume::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->jobTitle(),
            'source_file_path' => null,
            'source_mime' => 'application/pdf',
            'parsed_text' => null,
            'structured_json' => null,
            'parse_status' => 'pending',
            'is_default' => false,
        ];
    }

    public function parsed(): self
    {
        return $this->state(['parse_status' => 'parsed', 'parsed_text' => 'Parsed resume text.']);
    }

    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }
}
