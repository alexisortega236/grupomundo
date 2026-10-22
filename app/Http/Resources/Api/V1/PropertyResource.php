<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    public function toArray($request): array
    {
        $type = $this->propertyType;
        $showExactLocation = (bool) $this->show_exact_location;

        return [
            'external_id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'property_type' => [
                'name' => $type?->name ?: $this->property_type,
                'slug' => $type?->slug,
                'classification' => $type?->classification,
            ],
            'operation_type' => $this->operation_type?->value,
            'price' => $this->price,
            'currency' => $this->currency,
            'rent_period' => $this->rent_period,
            'status' => $this->status?->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'location' => [
                'street' => $showExactLocation ? $this->street : null,
                'exterior_number' => $showExactLocation ? $this->exterior_number : null,
                'interior_number' => $showExactLocation ? $this->interior_number : null,
                'neighborhood' => $this->neighborhood,
                'locality' => $this->locality,
                'municipality' => $this->municipality,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'latitude' => $showExactLocation ? $this->latitude : null,
                'longitude' => $showExactLocation ? $this->longitude : null,
                'show_exact_location' => $showExactLocation,
            ],
            'areas' => [
                'land_m2' => $this->land_area_m2 ?? $this->land_area,
                'construction_m2' => $this->construction_area_m2 ?? $this->construction_area,
            ],
            'features' => [
                'bedrooms' => $this->bedrooms,
                'full_bathrooms' => $this->full_bathrooms,
                'half_bathrooms' => $this->half_bathrooms,
                'bathrooms_total' => $this->bathrooms,
                'parking_spaces' => $this->parking_spaces,
                'age_years' => $this->property_age_years,
            ],
            'amenities' => $this->amenities->map(fn ($amenity) => [
                'external_id' => $amenity->id,
                'name' => $amenity->name,
                'slug' => $amenity->slug,
            ])->values(),
            'images' => PropertyImageResource::collection(
                $this->images->filter(function ($image) use ($request): bool {
                    return PropertyImageResource::isApiSafe($image, $request);
                })->values(),
            ),
            'source_created_at' => $this->created_at?->toIso8601String(),
            'source_updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
