<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocioeconomicZone extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'geometry' => 'array',
            'raw_data' => 'array',
            'population_density' => 'decimal:4',
            'housing_density' => 'decimal:4',
            'avg_household_size' => 'decimal:4',
            'internet_access_ratio' => 'decimal:4',
            'car_ownership_ratio' => 'decimal:4',
            'education_index' => 'decimal:4',
            'urbanization_score' => 'decimal:4',
            'socioeconomic_score' => 'decimal:4',
        ];
    }
}
