<?php

namespace App\Services\Valuation;

use App\Data\Avm\AvmPrediction;
use App\Exceptions\AvmClientException;
use App\Models\Property;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class AvmClient
{
    public function __construct(private readonly LegacyAvmPayloadBuilder $payloadBuilder)
    {
    }

    public function predict(Property $property, array $features): AvmPrediction
    {
        $baseUrl = rtrim((string) config('services.avm.url'), '/');

        if ($baseUrl === '') {
            throw new AvmClientException('avm_not_configured', 'El microservicio AVM no está configurado.');
        }

        $payload = $this->payloadBuilder->build($property, $features);

        try {
            $request = Http::timeout((int) config('services.avm.timeout', 10))
                ->acceptJson()
                ->asJson();

            if ($token = config('services.avm.token')) {
                $request = $request->withToken($token);
            }

            $response = $request->post($baseUrl.'/predict', $payload);
        } catch (ConnectionException $exception) {
            Log::warning('AVM service connection failed', ['message' => $exception->getMessage()]);
            throw new AvmClientException('avm_connection_failed', 'No fue posible conectar con el microservicio AVM.', $exception);
        }

        if ($response->failed()) {
            $errorPayload = $response->json();
            Log::warning('AVM service returned an error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $remoteCode = is_array($errorPayload) && isset($errorPayload['error']) ? (string) $errorPayload['error'] : null;
            $code = in_array($remoteCode, ['poi_provider_unavailable', 'invalid_coordinates', 'invalid_property_type'], true)
                ? $remoteCode
                : 'avm_http_error';

            throw new AvmClientException(
                $code,
                is_array($errorPayload) && isset($errorPayload['message']) ? (string) $errorPayload['message'] : 'El microservicio AVM devolvió un error HTTP '.$response->status().'.'
            );
        }

        try {
            $data = $response->json() ?? json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AvmClientException('avm_invalid_json', 'El microservicio AVM devolvió JSON inválido.', $exception);
        }

        $prediction = AvmPrediction::fromArray($data);

        if ($prediction->estimatedValue === null) {
            throw new AvmClientException('avm_incomplete_response', 'La respuesta del microservicio AVM no contiene los campos mínimos esperados.');
        }

        return $prediction;
    }

}
