<?php

namespace App\Services\Valuation;

use App\Models\Property;

class FeatureBuilder
{
    public function build(Property $property, array $enrichment = [], array $comparables = []): array
    {
        $features = [
            'construction_land_ratio' => $this->constructionLandRatio($property),
        ];

        return array_filter($features + $enrichment, fn ($value) => $value !== null);
    }

    private function constructionLandRatio(Property $property): ?float
    {
        $landArea = (float) ($property->land_area_m2 ?: $property->land_area);
        $constructionArea = (float) ($property->construction_area_m2 ?: $property->construction_area);

        if ($landArea <= 0 || $constructionArea <= 0) {
            return null;
        }

        return round($constructionArea / $landArea, 6);
    }
}
