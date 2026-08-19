<?php

namespace App\Services\Valuation;

use App\Models\PostalSettlement;
use Illuminate\Validation\ValidationException;

class PublicValuationLocationResolver
{
    public function __construct(private readonly PublicLocationGeocoder $geocoder)
    {
    }

    public function resolve(array $data): array
    {
        $settlement = $this->settlement($data);
        $hasCoordinates = filled($data['latitude'] ?? null) && filled($data['longitude'] ?? null);

        if ($hasCoordinates && ($data['location_source'] ?? null) === 'device') {
            return $this->withSettlementData($data, $settlement);
        }

        if ($settlement) {
            try {
                $location = $this->geocoder->geocode(
                    $settlement->state,
                    $settlement->municipality,
                    $settlement->settlement,
                    $settlement->postal_code
                );
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages([
                    'settlement_id' => 'No pudimos ubicar esa colonia. Revisa la selección o utiliza “Usar mi ubicación”.',
                ]);
            }

            return $this->withSettlementData(array_merge($data, $location), $settlement, [
                'location_source' => 'sepomex_geocoded',
                'location_precision' => 'neighborhood',
            ]);
        }

        if ($hasCoordinates) {
            return $data;
        }

        throw ValidationException::withMessages([
            'settlement_id' => 'Selecciona una colonia o utiliza “Usar mi ubicación”.',
        ]);
    }

    private function settlement(array $data): ?PostalSettlement
    {
        if (! filled($data['settlement_id'] ?? null)) {
            return null;
        }

        $settlement = PostalSettlement::query()->find($data['settlement_id']);
        if (! $settlement || $settlement->state !== ($data['state'] ?? 'Morelos') || $settlement->municipality !== ($data['municipality'] ?? null)) {
            throw ValidationException::withMessages([
                'settlement_id' => 'Selecciona una colonia válida dentro del municipio elegido.',
            ]);
        }

        return $settlement;
    }

    private function withSettlementData(array $data, ?PostalSettlement $settlement, array $overrides = []): array
    {
        if (! $settlement) {
            return $data + $overrides;
        }

        return array_merge($data, [
            'state' => $settlement->state,
            'municipality' => $settlement->municipality,
            'neighborhood' => $settlement->settlement,
            'postal_code' => $settlement->postal_code,
        ], $overrides);
    }
}
