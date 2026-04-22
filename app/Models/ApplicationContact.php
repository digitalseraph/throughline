<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationContact extends Model
{
    use HasFactory;

    public const ROLES = ['recruiter', 'hiring_manager', 'interviewer', 'referral', 'other'];

    protected $fillable = ['application_id', 'name', 'role', 'email', 'phone', 'linkedin_url', 'notes'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
