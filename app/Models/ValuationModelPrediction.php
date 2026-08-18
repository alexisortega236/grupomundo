<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValuationModelPrediction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'eligible' => 'boolean',
            'estimated_value' => 'decimal:2',
            'range_low' => 'decimal:2',
            'range_high' => 'decimal:2',
            'request_json' => 'array',
            'response_json' => 'array',
        ];
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class);
    }
}
