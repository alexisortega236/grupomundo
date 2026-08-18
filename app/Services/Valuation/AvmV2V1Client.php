<?php

namespace App\Services\Valuation;

use App\Exceptions\AvmClientException;
use App\Models\Property;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class AvmV2V1Client
{
    public function predict(Property $property): array
    {
        $baseUrl = rtrim((string) config('services.avm_v2_v1.url'), '/');

        if ($baseUrl === '') {
            throw new AvmClientException('avm_v2_v1_not_configured', 'El microservicio AVM v2 v1 no está configurado.');
        }

        $payload = $this->payload($property);

        try {
            $request = Http::timeout((int) config('services.avm_v2_v1.timeout', 20))
                ->acceptJson()
                ->asJson();

            if ($token = config('services.avm_v2_v1.token')) {
                $request = $request->withToken($token);
            }

            $response = $request->post($baseUrl.'/predict/v2/v1', $payload);
        } catch (ConnectionException $exception) {
            Log::warning('AVM v2 v1 service connection failed', ['message' => $exception->getMessage()]);
            throw new AvmClientException('avm_v2_v1_connection_failed', 'No fue posible conectar con AVM v2 v1.', $exception);
        }

        if ($response->failed()) {
            Log::warning('AVM v2 v1 service returned an error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new AvmClientException('avm_v2_v1_http_error', 'AVM v2 v1 devolvió un error HTTP '.$response->status().'.');
        }

        try {
            $data = $response->json() ?? json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AvmClientException('avm_v2_v1_invalid_json', 'AVM v2 v1 devolvió JSON inválido.', $exception);
        }

        if (! is_array($data) || ! array_key_exists('eligible', $data)) {
            throw new AvmClientException('avm_v2_v1_incomplete_response', 'AVM v2 v1 no devolvió una respuesta válida.');
        }

        return $data + ['_request' => $payload];
    }

    public function payload(Property $property): array
    {
        return [
            'property_type' => $property->property_type,
            'latitude' => $property->latitude !== null ? (float) $property->latitude : null,
            'longitude' => $property->longitude !== null ? (float) $property->longitude : null,
            'land_area_m2' => $property->land_area_m2 !== null ? (float) $property->land_area_m2 : null,
            'construction_area_m2' => $property->construction_area_m2 !== null ? (float) $property->construction_area_m2 : null,
            'bedrooms' => $property->bedrooms !== null ? (int) $property->bedrooms : null,
            'bathrooms' => $property->bathrooms !== null ? (float) $property->bathrooms : null,
            'parking_spaces' => $property->parking_spaces !== null ? (int) $property->parking_spaces : null,
            'age_years' => $property->property_age_years !== null ? (int) $property->property_age_years : null,
            'municipality' => $property->municipality,
            'neighborhood' => $property->neighborhood,
            'location_precision' => $property->location_precision,
            'coordinate_quality' => $property->location_precision === 'device' ? 'high' : 'medium',
        ];
    }
}
