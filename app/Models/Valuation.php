<?php

namespace App\Models;

use App\Enums\ValuationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Valuation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ValuationStatus::class,
            'estimated_value' => 'decimal:2',
            'estimated_price_m2' => 'decimal:2',
            'lower_bound' => 'decimal:2',
            'upper_bound' => 'decimal:2',
            'confidence_score' => 'decimal:4',
            'avm_response_json' => 'array',
            'valued_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    public function features(): HasOne
    {
        return $this->hasOne(ValuationFeature::class);
    }

    public function valuationComparables(): HasMany
    {
        return $this->hasMany(ValuationComparable::class);
    }

    public function modelPredictions(): HasMany
    {
        return $this->hasMany(ValuationModelPrediction::class);
    }
}
