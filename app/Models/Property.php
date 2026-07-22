<?php

namespace App\Models;

use App\Enums\OperationType;
use App\Enums\PropertyStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    /** @use HasFactory<\Database\Factories\PropertyFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'operation_type' => OperationType::class,
            'status' => PropertyStatus::class,
            'price' => 'decimal:2',
            'bathrooms' => 'decimal:1',
            'construction_area' => 'decimal:2',
            'land_area' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class)->orderBy('position');
    }

    public function coverImage(): HasMany
    {
        return $this->images()->where('is_cover', true);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class);
    }

    public function contactRequests(): HasMany
    {
        return $this->hasMany(ContactRequest::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PropertyStatus::Published->value)->whereNotNull('published_at');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeSale(Builder $query): Builder
    {
        return $query->where('operation_type', OperationType::Sale->value);
    }

    public function scopeRent(Builder $query): Builder
    {
        return $query->where('operation_type', OperationType::Rent->value);
    }

    public function getCoverUrlAttribute(): string
    {
        $image = $this->images->firstWhere('is_cover', true) ?? $this->images->first();

        return $image ? asset('storage/'.$image->path) : asset('images/property-placeholder.svg');
    }

    public function getLocationLabelAttribute(): string
    {
        return "{$this->neighborhood}, {$this->city}";
    }
}
