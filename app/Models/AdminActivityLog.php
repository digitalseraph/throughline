<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AdminActivityLog extends Model
{
    use HasFactory;

    protected $table = 'admin_activity_log';

    public $timestamps = false;

    protected $fillable = ['admin_user_id', 'action', 'target_type', 'target_id', 'payload', 'ip', 'created_at'];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('admin_activity_log rows are append-only.');
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
