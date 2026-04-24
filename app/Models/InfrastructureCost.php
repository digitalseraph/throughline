<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InfrastructureCost extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'amount_usd',
        'billing_cycle',
        'notes',
        'is_active',
        'updated_by_user_id',
    ];

    protected $casts = [
        'amount_usd' => 'decimal:2',
        'is_active' => 'bool',
    ];

    public function monthlyAmount(): float
    {
        return match ($this->billing_cycle) {
            'annual' => (float) $this->amount_usd / 12,
            default => (float) $this->amount_usd,
        };
    }
}
