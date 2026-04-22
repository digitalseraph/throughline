<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;

    public const STATUSES = ['saved', 'applied', 'phone_screen', 'technical', 'onsite', 'offer', 'rejected', 'withdrawn'];

    public const TERMINAL_STATUSES = ['offer', 'rejected', 'withdrawn'];

    protected $fillable = [
        'user_id',
        'canonical_job_id',
        'search_profile_id',
        'resume_id',
        'status',
        'applied_at',
        'next_action_at',
        'notes',
        'stage2_snapshot',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'next_action_at' => 'datetime',
        'stage2_snapshot' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canonicalJob(): BelongsTo
    {
        return $this->belongsTo(CanonicalJob::class);
    }

    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }

    public function searchProfile(): BelongsTo
    {
        return $this->belongsTo(SearchProfile::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class)->orderBy('occurred_at');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ApplicationContact::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES);
    }
}
