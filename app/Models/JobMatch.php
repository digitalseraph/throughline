<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobMatch extends Model
{
    public $timestamps = false;

    protected $table = 'job_matches';

    protected $fillable = [
        'user_id',
        'search_profile_id',
        'canonical_job_id',
        'stage1_score',
        'dismissed_at',
        'created_at',
    ];

    protected $casts = [
        'stage1_score' => 'float',
        'stage2_gaps' => 'array',
        'stage2_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SearchProfile::class, 'search_profile_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(CanonicalJob::class, 'canonical_job_id');
    }
}
