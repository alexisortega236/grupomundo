<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PropertyRevision extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $revision): void {
            $revision->id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'payload' => 'array',
            'committed_at' => 'datetime',
        ];
    }
}
