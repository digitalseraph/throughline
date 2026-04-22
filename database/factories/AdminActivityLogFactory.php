<?php

namespace Database\Factories;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdminActivityLogFactory extends Factory
{
    protected $model = AdminActivityLog::class;

    public function definition(): array
    {
        return [
            'admin_user_id' => User::factory()->state(['role' => 'admin']),
            'action' => 'test.action',
            'target_type' => null,
            'target_id' => null,
            'payload' => [],
            'ip' => '127.0.0.1',
            'created_at' => now(),
        ];
    }
}
