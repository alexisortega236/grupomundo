<?php

namespace App\Support;

use App\Models\Property;
use App\Models\PropertyType;

final class PropertyTypeCatalog
{
    /** @return array<string, string> */
    public static function options(bool $includeHistorical = true): array
    {
        $options = PropertyType::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();

        if ($includeHistorical) {
            $historical = Property::query()
                ->whereNotNull('property_type')
                ->distinct()
                ->pluck('property_type')
                ->filter()
                ->diff(array_keys($options))
                ->sort()
                ->mapWithKeys(fn (string $type) => [$type => $type])
                ->all();

            $options += $historical;
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function assignmentOptions(?Property $property = null): array
    {
        $options = self::options(false);
        $current = $property?->property_type;

        if (filled($current) && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }

    public static function label(?string $type): string
    {
        return filled($type) ? (string) $type : 'Propiedad';
    }
}
