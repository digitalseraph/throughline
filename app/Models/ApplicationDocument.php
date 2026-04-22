<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocument extends Model
{
    use HasFactory;

    public const KINDS = ['cover_letter', 'resume_snapshot', 'portfolio', 'other'];

    public $timestamps = false;

    protected $fillable = ['application_id', 'kind', 'file_path', 'file_name', 'mime', 'size_bytes', 'uploaded_at'];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
