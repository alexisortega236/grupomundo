<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PropertyVideo extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'position' => 'integer'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function url(): string
    {
        return Storage::url($this->path);
    }
}
