<?php

namespace App\Services\Valuation;

use App\Models\PostalSettlement;
use Illuminate\Support\Facades\Log;
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

        Log::info('Public valuation location resolution', [
            'postal_settlement_id' => $data['postal_settlement_id'] ?? null,
            'settlement' => $settlement?->settlement,
            'municipality' => $settlement?->municipality ?? ($data['municipality'] ?? null),
            'postal_code' => $settlement?->postal_code ?? ($data['postal_code'] ?? null),
            'has_coordinates' => $hasCoordinates,
        ]);

        if ($hasCoordinates && ($data['location_source'] ?? null) === 'device') {
            return $this->withSettlementData($data, $settlement);
        }

        if ($settlement) {
            try {
                Log::info('Public valuation geocoding settlement', [
                    'postal_settlement_id' => $settlement->id,
                    'settlement' => $settlement->settlement,
                    'municipality' => $settlement->municipality,
                    'postal_code' => $settlement->postal_code,
                ]);
                $location = $this->geocoder->geocode(
                    $settlement->state,
                    $settlement->municipality,
                    $settlement->settlement,
                    $settlement->postal_code
                );
                Log::info('Public valuation geocoding succeeded', [
                    'postal_settlement_id' => $settlement->id,
                    'latitude' => $location['latitude'] ?? null,
                    'longitude' => $location['longitude'] ?? null,
                    'location_precision' => $location['location_precision'] ?? null,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Public valuation settlement geocoding failed', [
                    'postal_settlement_id' => $settlement->id,
                    'reason' => $exception->getMessage(),
                ]);
                throw ValidationException::withMessages([
                    'postal_settlement_id' => 'No pudimos ubicar esa colonia. Revisa la selección o utiliza “Usar mi ubicación”.',
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
                'postal_settlement_id' => 'Selecciona una colonia o utiliza “Usar mi ubicación”.',
        ]);
    }

    private function settlement(array $data): ?PostalSettlement
    {
        $settlementId = $data['postal_settlement_id'] ?? $data['settlement_id'] ?? null;
        if (! filled($settlementId)) {
            return null;
        }

        $settlement = PostalSettlement::query()->find($settlementId);
        if (! $settlement || $settlement->state !== ($data['state'] ?? 'Morelos') || $settlement->municipality !== ($data['municipality'] ?? null)) {
            throw ValidationException::withMessages([
                'postal_settlement_id' => 'Selecciona una colonia válida dentro del municipio elegido.',
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
