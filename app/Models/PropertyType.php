<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyType extends Model
{
    public const CLASSIFICATION_RESIDENTIAL = 'residential';

    public const CLASSIFICATION_COMMERCIAL = 'commercial';

    public const CLASSIFICATION_LAND = 'land';

    /** @return array<string, string> */
    public static function classifications(): array
    {
        return [
            self::CLASSIFICATION_RESIDENTIAL => 'Residencial',
            self::CLASSIFICATION_COMMERCIAL => 'Comercial',
            self::CLASSIFICATION_LAND => 'Terreno',
        ];
    }

    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'property_type_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
