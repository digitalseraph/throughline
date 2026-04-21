<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'resume_id',
        'name',
        'role_keywords',
        'locations',
        'remote_mode',
        'comp_min',
        'comp_currency',
        'industries',
        'seniority_levels',
        'excluded_companies',
        'is_active',
    ];

    protected $casts = [
        'role_keywords' => 'array',
        'locations' => 'array',
        'industries' => 'array',
        'seniority_levels' => 'array',
        'excluded_companies' => 'array',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }
}
