<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PropertyImage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_cover' => 'boolean',
            'size_kb' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function url(string $version = 'large'): string
    {
        $path = match ($version) {
            'thumb' => $this->thumb_path ?: $this->card_path ?: $this->path,
            'card' => $this->card_path ?: $this->path,
            default => $this->path,
        };

        return Storage::url($path);
    }

    public function paths(): array
    {
        return array_values(array_filter([
            $this->path,
            $this->card_path,
            $this->thumb_path,
        ]));
    }
}
