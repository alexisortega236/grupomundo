<?php

namespace App\Services\Valuation;

use App\Exceptions\AvmClientException;
use App\Models\Property;

class LegacyAvmPayloadBuilder
{
    private const TYPE_MAP = [
        'house' => 'casa',
        'apartment' => 'depa',
        'land' => 'terreno',
    ];

    public function build(Property $property, array $features = []): array
    {
        $tipo = self::TYPE_MAP[$property->property_type] ?? null;

        if (! $tipo) {
            throw new AvmClientException('unsupported_property_type', 'El modelo AVM legacy no soporta el tipo de propiedad seleccionado.');
        }

        $colonia = $features['legacy_colonia'] ?? null;

        if (! $colonia) {
            throw new AvmClientException('missing_legacy_colonia', 'Selecciona una colonia compatible con el modelo AVM legacy.');
        }

        return [
            'tipo' => $tipo,
            'colonia' => $colonia,
            'm2_terreno' => (int) ($property->land_area_m2 ?: $property->land_area ?: 0),
            'm2_construccion' => (int) ($property->construction_area_m2 ?: $property->construction_area ?: 0),
            'recamaras' => (int) ($property->bedrooms ?: 0),
            'banos' => (int) ($property->bathrooms ?: 0),
            'estacionamientos' => (int) ($property->parking_spaces ?: 0),
            'antiguedad_anios' => (int) ($property->property_age_years ?: 0),
            'lat' => (float) $property->latitude,
            'lng' => (float) $property->longitude,
        ];
    }
}
