<?php

namespace App\Models;

use App\Enums\ContactRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactRequest extends Model
{
    /** @use HasFactory<\Database\Factories\ContactRequestFactory> */
    use HasFactory;

    protected $fillable = ['property_id', 'name', 'phone', 'email', 'message', 'status'];

    protected function casts(): array
    {
        return ['status' => ContactRequestStatus::class];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
