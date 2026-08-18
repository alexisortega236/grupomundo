<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comparable extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'land_area_m2' => 'decimal:2',
            'construction_area_m2' => 'decimal:2',
            'bathrooms' => 'decimal:1',
            'listing_price' => 'decimal:2',
            'listing_price_m2' => 'decimal:2',
            'publication_date' => 'date',
            'last_seen_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }

    public function valuationComparables(): HasMany
    {
        return $this->hasMany(ValuationComparable::class);
    }
}
