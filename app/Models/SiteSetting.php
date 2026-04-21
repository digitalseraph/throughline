<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description', 'updated_by'];

    protected $casts = [
        'value' => 'array',
    ];
}
