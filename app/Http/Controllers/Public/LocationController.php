<?php

namespace App\Http\Controllers\Public;

use App\Models\PostalSettlement;
use App\Services\Valuation\PublicLocationGeocoder;
use App\Services\Valuation\SupportedValuationLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class LocationController
{
    public function geocode(Request $request, PublicLocationGeocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'in:Morelos'],
            'municipality' => ['required', 'string', 'max:120'],
            'postal_settlement_id' => ['nullable', 'integer'],
            'neighborhood' => ['nullable', 'string', 'min:3', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:10'],
        ]);

        if (! array_key_exists($data['municipality'], app(SupportedValuationLocations::class)->municipalities())) {
            return response()->json(['message' => 'Selecciona un municipio disponible.'], 422);
        }

        $settlement = null;
        if (filled($data['postal_settlement_id'] ?? null)) {
            $settlement = PostalSettlement::query()
                ->whereKey($data['postal_settlement_id'])
                ->where('state', $data['state'])
                ->where('municipality', $data['municipality'])
                ->first();

            if (! $settlement) {
                return response()->json(['message' => 'Selecciona una colonia válida dentro del municipio elegido.'], 422);
            }
        }

        if (! $settlement && blank($data['neighborhood'] ?? null)) {
            return response()->json(['message' => 'Selecciona una colonia de la lista de sugerencias.'], 422);
        }

        try {
            return response()->json(['location' => $geocoder->geocode(
                $data['state'],
                $data['municipality'],
                $settlement?->settlement ?? $data['neighborhood'],
                $settlement?->postal_code ?? ($data['postal_code'] ?? null)
            )]);
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function reverse(Request $request, PublicLocationGeocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        try {
            return response()->json(['location' => $geocoder->reverse((float) $data['latitude'], (float) $data['longitude'])]);
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function municipalities(Request $request, SupportedValuationLocations $locations): JsonResponse
    {
        $state = $request->query('state', 'Morelos');
        if ($state !== 'Morelos') {
            return response()->json([]);
        }

        $catalogMunicipalities = PostalSettlement::query()
            ->where('state', $state)
            ->distinct()
            ->orderBy('municipality')
            ->pluck('municipality')
            ->values();

        return response()->json($catalogMunicipalities->isNotEmpty()
            ? $catalogMunicipalities
            : array_values($locations->municipalities()));
    }

    public function settlements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'in:Morelos'],
            'municipality' => ['required', 'string', 'max:120'],
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! array_key_exists($data['municipality'], app(SupportedValuationLocations::class)->municipalities())) {
            return response()->json(['message' => 'Selecciona un municipio disponible.'], 422);
        }

        $query = Str::of($data['q'])->trim()->value();
        $settlements = PostalSettlement::query()
            ->where('state', $data['state'])
            ->where('municipality', $data['municipality'])
            ->where('settlement', 'like', '%'.$query.'%')
            ->orderBy('settlement')
            ->limit(15)
            ->get(['id', 'settlement', 'settlement_type', 'postal_code'])
            ->map(fn (PostalSettlement $settlement) => [
                'id' => $settlement->id,
                'name' => $settlement->settlement,
                'type' => $settlement->settlement_type,
                'postal_code' => $settlement->postal_code,
            ])
            ->values();

        return response()->json($settlements);
    }
}
