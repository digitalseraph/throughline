<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfrastructureCostSnapshot extends Model
{
    protected $fillable = [
        'snapshot_month',
        'fixed_costs_usd',
        'ai_costs_usd',
        'total_usd',
        'line_items_snapshot',
    ];

    protected $casts = [
        'snapshot_month' => 'date',
        'fixed_costs_usd' => 'decimal:2',
        'ai_costs_usd' => 'decimal:2',
        'total_usd' => 'decimal:2',
        'line_items_snapshot' => 'array',
    ];
}
