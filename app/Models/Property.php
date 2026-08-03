<?php

namespace App\Models;

use App\Enums\OperationType;
use App\Enums\PropertyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Property extends Model
{
    /** @use HasFactory<\Database\Factories\PropertyFactory> */
    use HasFactory, SoftDeletes;

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
        return $this->hasMany(PropertyImage::class)->where('is_cover', true)->orderBy('position');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class);
    }

    public function contactRequests(): HasMany
    {
        return $this->hasMany(ContactRequest::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', PropertyStatus::Published)->whereNotNull('published_at');
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeSale($query)
    {
        return $query->where('operation_type', OperationType::Sale);
    }

    public function scopeRent($query)
    {
        return $query->where('operation_type', OperationType::Rent);
    }

    public function coverUrl(): string
    {
        $image = $this->coverImage->first() ?: $this->images->first();

        return $image ? Storage::url($image->path) : 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1200&q=80';
    }

    public function formattedPrice(): string
    {
        return '$'.number_format((float) $this->price, 0).' '.$this->currency;
    }
}
