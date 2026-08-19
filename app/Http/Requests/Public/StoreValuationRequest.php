<?php

namespace App\Http\Requests\Public;

use App\Enums\AvmPropertyType;
use App\Models\PostalSettlement;
use App\Services\Valuation\LegacyLocationMapper;
use App\Services\Valuation\SupportedValuationLocations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class StoreValuationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $typedNeighborhood = $this->input('neighborhood');
        $settlement = filled($this->input('settlement_id'))
            ? PostalSettlement::query()->find($this->input('settlement_id'))
            : null;

        $this->merge([
            'state' => $this->input('state') ?: 'Morelos',
            'locality' => $this->input('locality') ?: $this->input('municipality'),
            'legacy_colonia' => app(LegacyLocationMapper::class)->map($this->all()),
            '_typed_neighborhood' => $typedNeighborhood,
            ...($settlement ? [
                'state' => $settlement->state,
                'municipality' => $settlement->municipality,
                'neighborhood' => $settlement->settlement,
                'postal_code' => $settlement->postal_code,
            ] : []),
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
            'municipality' => ['nullable', Rule::in(array_keys(app(SupportedValuationLocations::class)->municipalities()))],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'locality' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'settlement_id' => ['nullable', 'integer'],
            'state' => ['nullable', Rule::in(['Morelos'])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_source' => ['nullable', Rule::in(['device', 'manual_geocode', 'sepomex_geocoded'])],
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
            'municipality.in' => 'Selecciona un municipio disponible para el valuador.',
            'state.in' => 'Por ahora el valuador está disponible para Morelos.',
            'latitude.numeric' => 'La ubicación seleccionada no es válida.',
            'latitude.between' => 'La ubicación seleccionada no es válida.',
            'longitude.numeric' => 'La ubicación seleccionada no es válida.',
            'longitude.between' => 'La ubicación seleccionada no es válida.',
            'settlement_id.integer' => 'Selecciona una colonia de la lista de sugerencias.',
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasCoordinates = filled($this->input('latitude')) && filled($this->input('longitude'));
            $settlementId = $this->input('settlement_id');

            if (! $hasCoordinates && ! filled($settlementId)) {
                $validator->errors()->add('settlement_id', 'Selecciona una colonia o utiliza “Usar mi ubicación”.');
            }

            if (! filled($settlementId)) {
                return;
            }

            $settlement = PostalSettlement::query()->find($settlementId);
            if (! $settlement
                || $settlement->state !== ($this->input('state') ?: 'Morelos')
                || $settlement->municipality !== $this->input('municipality')) {
                $validator->errors()->add('settlement_id', 'Selecciona una colonia válida dentro del municipio elegido.');
                return;
            }

            $typedNeighborhood = $this->input('_typed_neighborhood');
            if (filled($typedNeighborhood) && $this->normalize($typedNeighborhood) !== $this->normalize($settlement->settlement)) {
                $validator->errors()->add('settlement_id', 'Selecciona nuevamente la colonia después de modificar el texto.');
            }
        });
    }

    private function normalize(string $value): string
    {
        return (string) Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->value();
    }
}
