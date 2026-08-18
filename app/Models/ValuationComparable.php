<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValuationComparable extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'distance_m' => 'decimal:2',
            'similarity_score' => 'decimal:4',
            'weight' => 'decimal:4',
            'adjusted_price' => 'decimal:2',
            'adjusted_price_m2' => 'decimal:2',
            'adjustments_json' => 'array',
        ];
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class);
    }

    public function comparable(): BelongsTo
    {
        return $this->belongsTo(Comparable::class);
    }
}
