<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resume extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'source_file_path',
        'source_mime',
        'parsed_text',
        'structured_json',
        'parse_status',
        'is_default',
    ];

    protected $casts = [
        'structured_json' => 'array',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->parse_status === 'parsed' && ! is_null($this->embedding_raw());
    }

    public function embedding_raw(): ?string
    {
        $row = \DB::table('resumes')->where('id', $this->id)->value('embedding');

        return $row;
    }
}
