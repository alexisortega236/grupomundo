<?php

namespace App\Models;

use App\Enums\ModelVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'training_started_at' => 'datetime',
            'training_completed_at' => 'datetime',
            'mae' => 'decimal:4',
            'mape' => 'decimal:4',
            'rmse' => 'decimal:4',
            'r2' => 'decimal:4',
            'features_json' => 'array',
            'status' => ModelVersionStatus::class,
        ];
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(Valuation::class);
    }
}
