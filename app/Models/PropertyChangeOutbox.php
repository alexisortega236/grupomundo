<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PropertyChangeOutbox extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'property_change_outbox';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'committed_at' => 'datetime',
            'locked_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
