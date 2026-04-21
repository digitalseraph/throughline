<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CanonicalJob extends Model
{
    use HasFactory;

    protected $table = 'canonical_jobs';

    protected $fillable = [
        'source', 'source_id', 'source_board', 'company_name', 'title', 'description',
        'location_raw', 'locations', 'remote_mode', 'comp_min', 'comp_max', 'comp_currency',
        'seniority', 'employment_type', 'apply_url', 'posted_at', 'last_seen_at', 'closed_at',
        'raw_payload',
    ];

    protected $casts = [
        'locations' => 'array',
        'raw_payload' => 'array',
        'posted_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
