<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ApplicationEvent extends Model
{
    use HasFactory;

    public const TYPES = ['status_change', 'note', 'interview', 'reminder', 'contact_added', 'document_attached'];

    protected $fillable = [
        'application_id',
        'type',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Append-only invariant — updates at the Eloquent layer are rejected.
        // Corrections are done by writing compensating events instead.
        static::updating(function (ApplicationEvent $event) {
            throw new LogicException('application_events rows are append-only. Write a compensating event instead.');
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
