<?php

namespace App\Http\Controllers\Public;

use App\Services\Valuation\PublicLocationGeocoder;
use App\Services\Valuation\SupportedValuationLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class LocationController
{
    public function geocode(Request $request, PublicLocationGeocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'in:Morelos'],
            'municipality' => ['required', 'string', 'max:120'],
            'neighborhood' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        if (! array_key_exists($data['municipality'], app(SupportedValuationLocations::class)->municipalities())) {
            return response()->json(['message' => 'Selecciona un municipio disponible.'], 422);
        }

        try {
            return response()->json(['location' => $geocoder->geocode(...$data)]);
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
}
