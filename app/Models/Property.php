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
use InvalidArgumentException;

class Property extends Model
{
    public const ORIGIN_COMMERCIAL = 'commercial';

    public const ORIGIN_VALUATION = 'valuation';

    /** @use HasFactory<\Database\Factories\PropertyFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $attributes = [
        'origin' => self::ORIGIN_COMMERCIAL,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $property): void {
            if (! in_array($property->origin, [self::ORIGIN_COMMERCIAL, self::ORIGIN_VALUATION], true)) {
                throw new InvalidArgumentException('El origen de una property debe ser commercial o valuation.');
            }
        });

        static::updating(function (self $property): void {
            if ($property->isDirty('origin')) {
                throw new InvalidArgumentException('El origen de una property no puede modificarse después de su creación.');
            }
        });
    }

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
            'land_area_m2' => 'decimal:2',
            'construction_area_m2' => 'decimal:2',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(Valuation::class);
    }

    public function scopeCommercial($query)
    {
        return $query->where('origin', self::ORIGIN_COMMERCIAL);
    }

    public function scopeValuationOrigin($query)
    {
        return $query->where('origin', self::ORIGIN_VALUATION);
    }

    public function scopePublishable($query)
    {
        return $query->commercial();
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
        return $query->publishable()
            ->where('status', PropertyStatus::Published)
            ->whereNotNull('published_at');
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

    public function coverUrl(string $version = 'large'): string
    {
        $image = $this->coverImage->first() ?: $this->images->first();

        return $image ? $image->url($version) : 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1200&q=80';
    }

    public function formattedPrice(): string
    {
        $price = (float) $this->price;
        $decimals = floor($price) === $price ? 0 : 2;

        return '$'.number_format($price, $decimals).' '.strtoupper($this->currency ?: 'MXN');
    }

    public function formattedPriceWithPeriod(): string
    {
        $price = $this->formattedPrice();

        if ($this->operation_type->includesRent()) {
            return $price.' / '.($this->rent_period ?: 'mes');
        }

        return $price;
    }

    public function displayAddress(): string
    {
        $streetLine = collect([$this->street, $this->exterior_number])
            ->filter(fn ($value) => filled($value))
            ->implode(' ');

        return collect([$streetLine, $this->neighborhood, $this->city, $this->state])
            ->filter(fn ($value) => filled($value))
            ->implode(', ');
    }
}
