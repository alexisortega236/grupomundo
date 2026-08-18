<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Poi extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geometry' => 'array',
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
