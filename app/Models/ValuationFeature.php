<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValuationFeature extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'features_json' => 'array',
            'construction_land_ratio' => 'decimal:6',
            'nearest_school_distance_m' => 'decimal:2',
            'nearest_hospital_distance_m' => 'decimal:2',
            'nearest_supermarket_distance_m' => 'decimal:2',
            'nearest_park_distance_m' => 'decimal:2',
            'population_density' => 'decimal:4',
            'housing_density' => 'decimal:4',
            'socioeconomic_score' => 'decimal:4',
            'commercial_density_score' => 'decimal:4',
            'services_density_score' => 'decimal:4',
            'education_density_score' => 'decimal:4',
            'health_density_score' => 'decimal:4',
            'accessibility_score' => 'decimal:4',
            'median_price_m2_500m' => 'decimal:2',
            'median_price_m2_1km' => 'decimal:2',
            'median_price_m2_3km' => 'decimal:2',
            'weighted_comparable_price_m2' => 'decimal:2',
        ];
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class);
    }
}
