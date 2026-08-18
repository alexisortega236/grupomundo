<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketIndex extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'index_value' => 'decimal:4',
            'annual_change' => 'decimal:4',
            'quarterly_change' => 'decimal:4',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }
}
