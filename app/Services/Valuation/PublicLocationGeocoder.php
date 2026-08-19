<?php

namespace App\Services\Valuation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PublicLocationGeocoder
{
    public function geocode(string $state, string $municipality, string $neighborhood, ?string $postalCode = null): array
    {
        $queries = [
            implode(', ', array_filter([$neighborhood, $municipality, $state, $postalCode, 'México'])),
            implode(', ', [$neighborhood, $municipality, $state, 'México']),
            implode(', ', array_filter([$neighborhood, $postalCode, $state, 'México'])),
            implode(', ', array_filter([$postalCode, $municipality, $state, 'México'])),
            implode(', ', [$neighborhood, $state, 'México']),
        ];

        $best = null;
        $bestScore = -1;

        foreach ($queries as $index => $query) {
            Log::info('Public location geocoder query', [
                'attempt' => $index + 1,
                'query' => $query,
            ]);

            try {
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
            } catch (RuntimeException $exception) {
                Log::warning('Public location geocoder query failed', [
                    'attempt' => $index + 1,
                    'query' => $query,
                    'reason' => $exception->getMessage(),
                ]);
                continue;
            }

            Log::info('Public location geocoder results', [
                'attempt' => $index + 1,
                'query' => $query,
                'count' => is_countable($places) ? count($places) : 0,
            ]);

            foreach (is_array($places) ? $places : [] as $place) {
                $reason = $this->invalidPlaceReason($place, $state, $municipality);
                $displayName = (string) ($place['display_name'] ?? '');
                $address = $place['address'] ?? [];

                if ($reason !== null) {
                    Log::info('Public location geocoder candidate discarded', [
                        'attempt' => $index + 1,
                        'display_name' => $displayName,
                        'address_keys' => array_keys($address),
                        'reason' => $reason,
                    ]);
                    continue;
                }

                $score = $this->scorePlace($place, $municipality, $neighborhood, $postalCode);
                Log::info('Public location geocoder candidate accepted', [
                    'attempt' => $index + 1,
                    'display_name' => $displayName,
                    'address_keys' => array_keys($address),
                    'score' => $score,
                ]);

                if ($score > $bestScore) {
                    $best = $place;
                    $bestScore = $score;
                }
            }
        }

        if ($best === null) {
            throw new RuntimeException('No encontramos esa colonia dentro del municipio seleccionado.');
        }

        Log::info('Public location geocoder selected result', [
            'display_name' => $best['display_name'] ?? null,
            'latitude' => $best['lat'] ?? null,
            'longitude' => $best['lon'] ?? null,
            'score' => $bestScore,
        ]);

        return $this->normalizePlace($best, 'manual_geocode');
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

    private function request(string $path, array $query): Response
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => config('services.nominatim.user_agent', 'GrupoMundoPatrimonialValuador/1.0'),
                'Accept' => 'application/json',
                'Accept-Language' => 'es-MX,es;q=0.9',
            ])->timeout((int) config('services.nominatim.timeout', 8))
                ->get(rtrim((string) config('services.nominatim.url'), '/').$path, $query);
        } catch (ConnectionException $exception) {
            Log::warning('Public location geocoder connection failed', ['message' => $exception->getMessage()]);
            throw new RuntimeException('No fue posible consultar la ubicación en este momento.', previous: $exception);
        }

        Log::info('Public location geocoder HTTP response', [
            'path' => $path,
            'status' => $response->status(),
        ]);

        if ($response->failed()) {
            $message = match ($response->status()) {
                403 => 'El proveedor de ubicación rechazó la solicitud.',
                429 => 'El proveedor de ubicación solicitó esperar antes de intentar nuevamente.',
                default => 'No fue posible consultar la ubicación en este momento.',
            };

            throw new RuntimeException($message);
        }

        return $response;
    }

    private function invalidPlaceReason(array $place, string $state, string $municipality): ?string
    {
        $address = $place['address'] ?? [];
        $latitude = filter_var($place['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($place['lon'] ?? null, FILTER_VALIDATE_FLOAT);

        if (! $this->matchesCountry($address, $place)) {
            return 'country_mismatch';
        }

        if (! $this->matchesState($address, $state)) {
            return 'state_mismatch';
        }

        if ($latitude === false || $longitude === false || ! $this->insideMorelos((float) $latitude, (float) $longitude)) {
            return 'coordinates_outside_morelos';
        }

        $municipalityMatch = $this->municipalityMatch($address, $place, $municipality);
        if ($municipalityMatch === false) {
            return 'municipality_mismatch';
        }

        return null;
    }

    private function scorePlace(array $place, string $municipality, string $neighborhood, ?string $postalCode): int
    {
        $address = $place['address'] ?? [];
        $score = 30;
        $municipalityMatch = $this->municipalityMatch($address, $place, $municipality);
        $neighborhoodMatch = $this->neighborhoodMatch($address, $place, $neighborhood);

        if ($municipalityMatch === true) {
            $score += 30;
        }

        if ($neighborhoodMatch === true) {
            $score += 30;
        }

        if ($postalCode && $this->normalize((string) ($address['postcode'] ?? '')) === $this->normalize($postalCode)) {
            $score += 25;
        }

        if ($this->neighborhoodFromAddress($address)) {
            $score += 10;
        } elseif ($address['postcode'] ?? null) {
            $score += 5;
        }

        return $score;
    }

    private function normalizePlace(array $place, string $source): array
    {
        $address = $place['address'] ?? [];
        $municipality = $this->canonicalMunicipality($this->municipalityFromAddress($address));
        $neighborhood = $this->neighborhoodFromAddress($address);

        return [
            'latitude' => (float) ($place['lat'] ?? 0),
            'longitude' => (float) ($place['lon'] ?? 0),
            'state' => $address['state'] ?? 'Morelos',
            'municipality' => $municipality,
            'locality' => $address['city'] ?? $address['town'] ?? $address['village'] ?? $municipality,
            'neighborhood' => $neighborhood,
            'postal_code' => $address['postcode'] ?? null,
            'location_source' => $source,
            'location_precision' => $source === 'device' ? 'device' : ($neighborhood ? 'neighborhood' : (($address['postcode'] ?? null) ? 'postal_code' : 'locality')),
        ];
    }

    private function municipalityFromAddress(array $address): ?string
    {
        $fallback = null;

        foreach (['municipality', 'county', 'city_district', 'city', 'town', 'village'] as $key) {
            if (filled($address[$key] ?? null)) {
                $fallback ??= (string) $address[$key];
                $canonical = $this->canonicalMunicipality((string) $address[$key]);
                if ($canonical && array_key_exists($canonical, app(SupportedValuationLocations::class)->municipalities())) {
                    return $canonical;
                }
            }
        }

        return $fallback;
    }

    private function neighborhoodFromAddress(array $address): ?string
    {
        foreach (['neighbourhood', 'neighborhood', 'suburb', 'residential', 'quarter'] as $key) {
            if (filled($address[$key] ?? null)) {
                return (string) $address[$key];
            }
        }

        return null;
    }

    private function matchesCountry(array $address, array $place = []): bool
    {
        return $this->normalize((string) ($address['country_code'] ?? '')) === 'mx'
            || $this->normalize((string) ($address['country'] ?? '')) === 'mexico'
            || Str::contains($this->normalize((string) ($place['display_name'] ?? '')), 'mexico');
    }

    private function matchesState(array $address, string $state): bool
    {
        return $this->normalize((string) ($address['state'] ?? '')) === $this->normalize($state);
    }

    private function municipalityMatch(array $address, array $place, string $municipality): ?bool
    {
        $target = $this->normalize($municipality);
        $values = array_filter([
            $address['municipality'] ?? null,
            $address['county'] ?? null,
            $address['city_district'] ?? null,
            $address['city'] ?? null,
            $address['town'] ?? null,
            $address['village'] ?? null,
            $place['display_name'] ?? null,
        ]);

        if (! $values) {
            return null;
        }

        foreach ($values as $value) {
            $normalized = $this->normalize((string) $value);
            if ($normalized === $target || Str::contains($normalized, $target) || Str::contains($target, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function neighborhoodMatch(array $address, array $place, string $neighborhood): ?bool
    {
        $target = $this->normalize($neighborhood);
        $values = array_filter([
            $this->neighborhoodFromAddress($address),
            $place['display_name'] ?? null,
        ]);

        if (! $values) {
            return null;
        }

        foreach ($values as $value) {
            $normalized = $this->normalize((string) $value);
            if ($normalized === $target || Str::contains($normalized, $target) || Str::contains($target, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function insideMorelos(float $latitude, float $longitude): bool
    {
        return $latitude >= 18.2 && $latitude <= 19.2 && $longitude >= -99.6 && $longitude <= -98.5;
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
