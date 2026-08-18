<?php

namespace App\Http\Requests\Public;

use App\Enums\AvmPropertyType;
use App\Services\Valuation\LegacyLocationMapper;
use App\Services\Valuation\SupportedValuationLocations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreValuationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'state' => $this->input('state') ?: 'Morelos',
            'locality' => $this->input('locality') ?: $this->input('municipality'),
            'legacy_colonia' => app(LegacyLocationMapper::class)->map($this->all()),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_type' => ['required', Rule::in([
                AvmPropertyType::House->value,
                AvmPropertyType::Apartment->value,
                AvmPropertyType::Land->value,
            ])],
            'legacy_colonia' => ['nullable', 'string', 'max:40'],
            'municipality' => ['required', Rule::in(array_keys(app(SupportedValuationLocations::class)->municipalities()))],
            'neighborhood' => ['required', 'string', 'max:120'],
            'locality' => ['nullable', 'string', 'max:120'],
            'state' => ['required', Rule::in(['Morelos'])],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_source' => ['nullable', Rule::in(['device', 'manual_geocode'])],
            'location_precision' => ['nullable', Rule::in(['device', 'neighborhood', 'locality', 'municipality'])],
            'land_area_m2' => ['required_unless:property_type,apartment', 'nullable', 'numeric', 'gt:0'],
            'construction_area_m2' => ['required_unless:property_type,land', 'nullable', 'numeric', 'gt:0'],
            'bedrooms' => ['nullable', 'integer', 'min:0'],
            'bathrooms' => ['nullable', 'numeric', 'min:0'],
            'parking_spaces' => ['nullable', 'integer', 'min:0'],
            'property_age_years' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'property_type.required' => 'Selecciona el tipo de propiedad.',
            'property_type.in' => 'Selecciona un tipo de propiedad disponible para el valuador.',
            'municipality.required' => 'Selecciona el municipio de la propiedad.',
            'municipality.in' => 'Selecciona un municipio disponible para el valuador.',
            'neighborhood.required' => 'Escribe la colonia o fraccionamiento.',
            'state.required' => 'Selecciona el estado de la propiedad.',
            'state.in' => 'Por ahora el valuador está disponible para Morelos.',
            'latitude.required' => 'Usa tu ubicación o busca la colonia para ubicar la propiedad.',
            'latitude.numeric' => 'Usa tu ubicación o busca la colonia para ubicar la propiedad.',
            'latitude.between' => 'La ubicación seleccionada no es válida.',
            'longitude.required' => 'Usa tu ubicación o busca la colonia para ubicar la propiedad.',
            'longitude.numeric' => 'Usa tu ubicación o busca la colonia para ubicar la propiedad.',
            'longitude.between' => 'La ubicación seleccionada no es válida.',
            'land_area_m2.required_unless' => 'Ingresa la superficie del terreno.',
            'land_area_m2.numeric' => 'La superficie del terreno debe ser numérica.',
            'land_area_m2.gt' => 'La superficie del terreno debe ser mayor a 0.',
            'construction_area_m2.required_unless' => 'Ingresa la superficie de construcción.',
            'construction_area_m2.numeric' => 'La superficie de construcción debe ser numérica.',
            'construction_area_m2.gt' => 'La superficie de construcción debe ser mayor a 0.',
            'bedrooms.integer' => 'Las recámaras deben ser un número entero.',
            'bedrooms.min' => 'Las recámaras no pueden ser negativas.',
            'bathrooms.numeric' => 'Los baños deben ser un número válido.',
            'bathrooms.min' => 'Los baños no pueden ser negativos.',
            'parking_spaces.integer' => 'Los estacionamientos deben ser un número entero.',
            'parking_spaces.min' => 'Los estacionamientos no pueden ser negativos.',
            'property_age_years.integer' => 'La antigüedad debe ser un número entero.',
            'property_age_years.min' => 'La antigüedad no puede ser negativa.',
        ];
    }
}
