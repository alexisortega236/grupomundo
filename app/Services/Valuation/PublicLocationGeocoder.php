<?php

namespace App\Services\Valuation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class PublicLocationGeocoder
{
    public function geocode(string $state, string $municipality, string $neighborhood, ?string $postalCode = null): array
    {
        $queries = array_values(array_filter([
            implode(', ', array_filter([$neighborhood, $municipality, $state, $postalCode, 'México'])),
            implode(', ', [$neighborhood, $municipality, $state, 'México']),
            implode(', ', [$municipality, $state, 'México']),
        ]));

        foreach ($queries as $index => $query) {
            $places = Cache::remember(
                'public-location:search:'.sha1($this->normalize($query)),
                now()->addHours(6),
                fn () => $this->request('/search', [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => 5,
                    'countrycodes' => 'mx',
                ])->json() ?? []
            );

            foreach ($places as $place) {
                $address = $place['address'] ?? [];

                if ($index < 2
                    && $this->matchesState($address, $state)
                    && $this->matchesMunicipality($address, $place, $municipality)
                    && $this->matchesNeighborhood($address, $place, $neighborhood)) {
                    return $this->normalizePlace($place, 'manual_geocode');
                }
            }
        }

        throw new RuntimeException('No encontramos esa colonia dentro del municipio seleccionado.');
    }

    public function reverse(float $latitude, float $longitude): array
    {
        $query = [
            'lat' => $latitude,
            'lon' => $longitude,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'zoom' => 18,
        ];
        $place = Cache::remember(
            'public-location:reverse:'.sha1($latitude.':'.$longitude),
            now()->addDay(),
            fn () => $this->request('/reverse', $query)->json() ?? []
        );

        $address = $place['address'] ?? [];
        $municipality = $this->canonicalMunicipality($this->municipalityFromAddress($address));

        if (! $this->matchesState($address, 'Morelos')
            || ! $municipality
            || ! array_key_exists($municipality, app(SupportedValuationLocations::class)->municipalities())) {
            throw new RuntimeException('La ubicación detectada está fuera de las zonas disponibles.');
        }

        return $this->normalizePlace($place, 'device');
    }

    private function request(string $path, array $query): \Illuminate\Http\Client\Response
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => config('services.nominatim.user_agent'),
                'Accept-Language' => 'es-MX,es;q=0.9',
            ])->timeout((int) config('services.nominatim.timeout', 8))
                ->get(rtrim((string) config('services.nominatim.url'), '/').$path, $query);
        } catch (ConnectionException $exception) {
            Log::warning('Public location geocoder connection failed', ['message' => $exception->getMessage()]);
            throw new RuntimeException('No fue posible consultar la ubicación en este momento.', previous: $exception);
        }

        if ($response->failed()) {
            Log::warning('Public location geocoder returned an error', ['status' => $response->status()]);
            throw new RuntimeException('No fue posible consultar la ubicación en este momento.');
        }

        return $response;
    }

    private function normalizePlace(array $place, string $source): array
    {
        $address = $place['address'] ?? [];
        $municipality = $this->canonicalMunicipality($this->municipalityFromAddress($address));
        $neighborhood = $address['neighbourhood']
            ?? $address['suburb']
            ?? $address['quarter']
            ?? $address['residential']
            ?? null;

        return [
            'latitude' => (float) ($place['lat'] ?? 0),
            'longitude' => (float) ($place['lon'] ?? 0),
            'state' => $address['state'] ?? 'Morelos',
            'municipality' => $municipality,
            'locality' => $address['city'] ?? $address['town'] ?? $address['village'] ?? $municipality,
            'neighborhood' => $neighborhood,
            'postal_code' => $address['postcode'] ?? null,
            'location_source' => $source,
            'location_precision' => $source === 'device' ? 'device' : ($neighborhood ? 'neighborhood' : 'locality'),
        ];
    }

    private function municipalityFromAddress(array $address): ?string
    {
        return $address['municipality']
            ?? $address['county']
            ?? $address['city_district']
            ?? $address['city']
            ?? $address['town']
            ?? $address['village']
            ?? null;
    }

    private function matchesState(array $address, string $state): bool
    {
        return $this->normalize($address['state'] ?? '') === $this->normalize($state);
    }

    private function matchesMunicipality(array $address, array $place, string $municipality): bool
    {
        $candidate = $this->municipalityFromAddress($address);
        $target = $this->normalize($municipality);

        return $candidate && ($this->normalize($candidate) === $target
            || Str::contains($this->normalize($candidate), $target)
            || Str::contains($this->normalize($place['display_name'] ?? ''), $target));
    }

    private function matchesNeighborhood(array $address, array $place, string $neighborhood): bool
    {
        $target = $this->normalize($neighborhood);
        $candidates = array_filter([
            $address['neighbourhood'] ?? null,
            $address['suburb'] ?? null,
            $address['quarter'] ?? null,
            $address['residential'] ?? null,
            $place['display_name'] ?? null,
        ]);

        foreach ($candidates as $candidate) {
            $normalized = $this->normalize((string) $candidate);
            if ($normalized === $target || Str::contains($normalized, $target) || Str::contains($target, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
    }

    private function canonicalMunicipality(?string $candidate): ?string
    {
        if (! $candidate) {
            return null;
        }

        foreach (app(SupportedValuationLocations::class)->municipalities() as $key => $label) {
            if ($this->normalize($candidate) === $this->normalize($key)
                || Str::contains($this->normalize($candidate), $this->normalize($key))) {
                return $key;
            }
        }

        return $candidate;
    }
}
