<?php

namespace App\Http\Requests\Admin;

use App\Enums\AvmPropertyType;
use App\Services\Valuation\LegacyAvmCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreValuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'property_type' => ['required', Rule::in([
                AvmPropertyType::House->value,
                AvmPropertyType::Apartment->value,
                AvmPropertyType::Land->value,
            ])],
            'legacy_colonia' => ['required', Rule::in(app(LegacyAvmCatalog::class)->values())],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'land_area_m2' => ['nullable', 'numeric', 'gt:0'],
            'construction_area_m2' => ['nullable', 'numeric', 'gt:0'],
            'bedrooms' => ['nullable', 'integer', 'min:0'],
            'bathrooms' => ['nullable', 'numeric', 'min:0'],
            'parking_spaces' => ['nullable', 'integer', 'min:0'],
            'property_age_years' => ['nullable', 'integer', 'min:0'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'locality' => ['nullable', 'string', 'max:120'],
            'municipality' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
        ];
    }
}
