<?php

namespace App\Models;

use App\Enums\ContactRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ContactRequestStatus::class,
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
