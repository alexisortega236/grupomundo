<?php

namespace App\Models;

use App\Enums\ImportRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ImportRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }
}
