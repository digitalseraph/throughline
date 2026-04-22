<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationEventFactory extends Factory
{
    protected $model = ApplicationEvent::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'type' => 'status_change',
            'payload' => ['from' => null, 'to' => 'saved'],
            'occurred_at' => now(),
        ];
    }
}
