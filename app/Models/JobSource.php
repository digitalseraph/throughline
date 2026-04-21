<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_type',
        'identifier',
        'display_name',
        'is_enabled',
        'fetch_cadence_minutes',
        'last_fetched_at',
        'last_error',
        'consecutive_failures',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_fetched_at' => 'datetime',
        'fetch_cadence_minutes' => 'integer',
        'consecutive_failures' => 'integer',
    ];
}
