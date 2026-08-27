<?php

namespace App\Services\Valuation;

use App\Data\Avm\AvmPrediction;
use App\Enums\OperationType;
use App\Enums\PropertyStatus;
use App\Enums\ValuationStatus;
use App\Exceptions\AvmClientException;
use App\Models\ModelVersion;
use App\Models\Property;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ValuationService
{
    public function __construct(
        private readonly LocationEnrichmentService $locationEnrichment,
        private readonly FeatureBuilder $featureBuilder,
        private readonly ComparableService $comparableService,
        private readonly AvmClient $avmClient,
        private readonly ResidentialAvmV2Client $residentialAvmV2Client,
        private readonly AvmV2V1Client $avmV2V1Client,
        private readonly LegacyAvmCatalog $legacyCatalog,
    ) {
    }

    public function createAndRun(array $data, ?User $user = null, string $source = 'admin'): Valuation
    {
        $valuation = DB::transaction(function () use ($data, $user, $source) {
            $property = Property::create($this->propertyPayload($data, $user));

            $valuation = $property->valuations()->create([
                'uuid' => (string) Str::uuid(),
                'source' => $source,
                'status' => ValuationStatus::Pending,
            ]);

            $valuation->features()->create([
                'features_json' => [],
            ]);

            return $valuation;
        });

        return $this->run($valuation->fresh(['property', 'features']));
    }

    public function run(Valuation $valuation): Valuation
    {
        $valuation->update(['status' => ValuationStatus::Processing]);

        try {
            $property = $valuation->property;
            $enrichment = $this->locationEnrichment->enrich($property);
            $comparables = $this->comparableService->findForProperty($property);
            $features = $this->featureBuilder->build($property, $enrichment + [
                'legacy_colonia' => $property->avm_colonia,
            ], $comparables->all());

            $valuation->features()->updateOrCreate(
                ['valuation_id' => $valuation->id],
                ['features_json' => $features, ...$this->knownFeatureColumns($features)]
            );

            [$prediction, $primaryModel] = $this->selectPrimaryPrediction($valuation, $property, $features);

            $estimatedPriceM2 = $prediction->estimatedPriceM2 ?: $this->estimatedPriceM2($prediction->estimatedValue, $property);
            $responseFeatures = $this->responseFeatures($prediction->derivedFeatures, $prediction->pois);

            $valuation->features()->updateOrCreate(
                ['valuation_id' => $valuation->id],
                [
                    'features_json' => $features + ['avm' => $responseFeatures],
                    ...$this->knownFeatureColumns($features + $responseFeatures),
                ]
            );

            $valuation->update([
                'model_version_id' => $this->resolveModelVersionId($prediction->modelVersion),
                'estimated_value' => $prediction->estimatedValue,
                'estimated_price_m2' => $estimatedPriceM2,
                'lower_bound' => $prediction->lowerBound,
                'upper_bound' => $prediction->upperBound,
                'confidence_score' => $prediction->confidenceScore,
                'comparables_count' => $comparables->count(),
                'currency' => $prediction->currency,
                'zone_inferred' => $prediction->zoneInferred,
                'avm_response_json' => $prediction->rawResponse,
                'status' => ValuationStatus::Completed,
                'valued_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);

            $this->runExperimentalShadows($valuation->fresh(['property']), $primaryModel);
        } catch (AvmClientException $exception) {
            $this->markFailed($valuation, $exception->errorCode, $exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('Unexpected valuation failure', [
                'valuation_id' => $valuation->id,
                'message' => $exception->getMessage(),
            ]);
            $this->markFailed($valuation, 'valuation_failed', 'No fue posible completar la valuación.');
        }

        return $valuation->fresh(['property', 'features']);
    }

    /** @return array{0: \App\Data\Avm\AvmPrediction, 1: string} */
    private function selectPrimaryPrediction(Valuation $valuation, Property $property, array $features): array
    {
        $attemptedNewModel = false;
        $failures = [];

        if (in_array($property->property_type, ['house', 'apartment'], true)
            && config('services.avm_v2.enabled', false)) {
            $attemptedNewModel = true;
            $started = microtime(true);
            $request = $this->residentialAvmV2Client->payload($property);

            try {
                $response = $this->residentialAvmV2Client->predict($property);
                $eligible = (bool) ($response['eligible'] ?? false);
                $this->recordModelPrediction($valuation, 'avm_residential_v2', $response, $request, $started);

                if ($eligible && isset($response['estimated_value'])) {
                    return [AvmPrediction::fromArray($response), 'avm_residential_v2'];
                }

                $failures[] = $response['reason'] ?? 'avm_residential_v2_ineligible';
            } catch (Throwable $exception) {
                $this->recordModelPrediction($valuation, 'avm_residential_v2', null, $request, $started, $exception);
                $failures[] = $exception instanceof AvmClientException ? $exception->errorCode : 'avm_residential_v2_failed';
            }
        }

        if (in_array($property->property_type, ['house', 'apartment', 'land'], true)
            && config('services.avm_v2_v1.enabled', false)) {
            $attemptedNewModel = true;
            $started = microtime(true);
            $request = $this->avmV2V1Client->payload($property);

            try {
                $response = $this->avmV2V1Client->predict($property);
                $eligible = (bool) ($response['eligible'] ?? false);
                $this->recordModelPrediction($valuation, 'avm_v2_v1', $response, $request, $started);

                if ($eligible && isset($response['estimated_value'])) {
                    return [AvmPrediction::fromArray($response), 'avm_v2_v1'];
                }

                $failures[] = $response['reason'] ?? 'avm_v2_v1_ineligible';
            } catch (Throwable $exception) {
                $this->recordModelPrediction($valuation, 'avm_v2_v1', null, $request, $started, $exception);
                $failures[] = $exception instanceof AvmClientException ? $exception->errorCode : 'avm_v2_v1_failed';
            }
        }

        if (! $attemptedNewModel) {
            return [$this->avmClient->predict($property, $features), 'legacy'];
        }

        throw new AvmClientException(
            'avm_models_ineligible',
            'Ningún modelo AVM disponible pudo generar una valuación elegible: '.implode(', ', $failures)
        );
    }

    private function propertyPayload(array $data, ?User $user): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'user_id' => $user?->id,
            'created_by' => $user?->id,
            'title' => 'Valuación '.$uuid,
            'slug' => 'valuacion-'.$uuid,
            'short_description' => 'Propiedad creada desde el módulo de valuación.',
            'description' => 'Propiedad creada desde el módulo de valuación inmobiliaria automatizada.',
            'operation_type' => OperationType::Sale->value,
            'origin' => Property::ORIGIN_VALUATION,
            'property_type' => $data['property_type'],
            'price' => 0,
            'currency' => 'MXN',
            'street' => $data['street'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
            'locality' => $data['locality'] ?? null,
            'municipality' => $data['municipality'] ?? null,
            'city' => $data['locality'] ?? $data['municipality'] ?? 'Por definir',
            'state' => $data['state'] ?? 'Por definir',
            'postal_code' => $data['postal_code'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'location_source' => $data['location_source'] ?? null,
            'location_precision' => $data['location_precision'] ?? null,
            'land_area' => $data['land_area_m2'] ?? null,
            'construction_area' => $data['construction_area_m2'] ?? null,
            'land_area_m2' => $data['land_area_m2'] ?? null,
            'construction_area_m2' => $data['construction_area_m2'] ?? null,
            'bedrooms' => $data['bedrooms'] ?? null,
            'bathrooms' => $data['bathrooms'] ?? null,
            'parking_spaces' => $data['parking_spaces'] ?? null,
            'property_age_years' => $data['property_age_years'] ?? null,
            'avm_colonia' => $data['legacy_colonia'] ?? null,
            'age' => isset($data['property_age_years']) ? $data['property_age_years'].' años' : null,
            'status' => PropertyStatus::Draft->value,
            'is_featured' => false,
        ];
    }

    private function knownFeatureColumns(array $features): array
    {
        $known = [
            'construction_land_ratio',
            'population_density',
            'housing_density',
            'socioeconomic_score',
            'commercial_density_score',
            'services_density_score',
            'education_density_score',
            'health_density_score',
            'nearest_school_distance_m',
            'nearest_hospital_distance_m',
            'nearest_supermarket_distance_m',
            'nearest_park_distance_m',
            'nearest_pharmacy_distance_m',
            'distance_to_primary_road_m',
            'distance_to_city_center_km',
            'accessibility_score',
            'median_price_m2_500m',
            'median_price_m2_1km',
            'median_price_m2_3km',
            'price_m2_p25',
            'price_m2_p50',
            'price_m2_p75',
            'weighted_comparable_price_m2',
            'market_trend_3m',
            'market_trend_12m',
        ];

        return collect($features)->only($known)->all();
    }

    private function resolveModelVersionId(?string $version): ?int
    {
        if (! $version) {
            return null;
        }

        return ModelVersion::firstOrCreate(
            ['name' => 'AVM', 'version' => $version],
            ['status' => 'active']
        )->id;
    }

    private function markFailed(Valuation $valuation, string $code, string $message): void
    {
        $valuation->update([
            'status' => ValuationStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
        ]);
    }

    private function estimatedPriceM2(?float $estimatedValue, Property $property): ?float
    {
        $constructionArea = (float) ($property->construction_area_m2 ?: $property->construction_area);

        if (! $estimatedValue || $constructionArea <= 0) {
            return null;
        }

        return round($estimatedValue / $constructionArea, 2);
    }

    private function responseFeatures(array $derivedFeatures, array $pois): array
    {
        $nearest = $pois['nearest_m'] ?? [];

        return array_filter([
            'cerca_escuelas' => $derivedFeatures['cerca_escuelas'] ?? null,
            'cerca_transporte' => $derivedFeatures['cerca_transporte'] ?? null,
            'nearest_school_distance_m' => $nearest['schools'] ?? null,
            'nearest_hospital_distance_m' => $nearest['hospitals'] ?? null,
            'nearest_supermarket_distance_m' => $nearest['supermarkets'] ?? null,
            'nearest_park_distance_m' => $nearest['parks'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function runResidentialV2Shadow(Valuation $valuation, array $executedModels): void
    {
        if (! config('services.avm_v2.enabled', false) || ! config('services.avm_v2.shadow_mode', true)
            || in_array('avm_residential_v2', $executedModels, true)) {
            return;
        }

        $property = $valuation->property;

        if (! in_array($property->property_type, ['house', 'apartment'], true)) {
            return;
        }

        $started = microtime(true);
        $request = $this->residentialAvmV2Client->payload($property);

        try {
            $response = $this->residentialAvmV2Client->predict($property);
            $this->recordModelPrediction($valuation, 'avm_residential_v2', $response, $request, $started);
        } catch (Throwable $exception) {
            Log::warning('AVM v2 shadow prediction failed', [
                'valuation_id' => $valuation->id,
                'message' => $exception->getMessage(),
            ]);

            $this->recordModelPrediction($valuation, 'avm_residential_v2', null, $request, $started, $exception);
        }
    }

    private function runExperimentalShadows(Valuation $valuation, string $primaryModel): void
    {
        // Cada modelo registra su propio estado; un fallo no debe impedir el otro.
        $this->runResidentialV2Shadow($valuation, [$primaryModel]);
        $this->runAvmV2V1Shadow($valuation, [$primaryModel]);
    }

    private function runAvmV2V1Shadow(Valuation $valuation, array $executedModels): void
    {
        if (! config('services.avm_v2_v1.enabled', false)
            || ! config('services.avm_v2.shadow_mode', true)
            || in_array('avm_v2_v1', $executedModels, true)) {
            return;
        }

        $property = $valuation->property;
        if (! in_array($property->property_type, ['house', 'apartment', 'land'], true)) {
            return;
        }

        $started = microtime(true);
        $request = $this->avmV2V1Client->payload($property);

        try {
            $response = $this->avmV2V1Client->predict($property);
            $this->recordModelPrediction($valuation, 'avm_v2_v1', $response, $request, $started);
        } catch (Throwable $exception) {
            Log::warning('AVM v2 v1 shadow prediction failed', [
                'valuation_id' => $valuation->id,
                'message' => $exception->getMessage(),
            ]);

            $this->recordModelPrediction($valuation, 'avm_v2_v1', null, $request, $started, $exception);
        }
    }

    private function recordModelPrediction(
        Valuation $valuation,
        string $modelName,
        ?array $response,
        array $request,
        float $started,
        ?Throwable $exception = null,
    ): void {
        $eligible = $exception === null
            && (bool) ($response['eligible'] ?? false)
            && isset($response['estimated_value']);
        $range = $response['range'] ?? [];

        $valuation->modelPredictions()->create([
            'model_name' => $modelName,
            'model_version' => ($response ?? [])['model_version']
                ?? ($response ?? [])['model']
                ?? ($modelName === 'avm_v2_v1' ? 'avm_v2_v1' : null),
            'status' => $exception !== null ? 'failed' : ($eligible ? 'completed' : 'ineligible'),
            'eligible' => $eligible,
            'estimated_value' => $eligible && isset($response['estimated_value']) ? (float) $response['estimated_value'] : null,
            'range_low' => $eligible && isset($range['low']) ? (float) $range['low'] : null,
            'range_high' => $eligible && isset($range['high']) ? (float) $range['high'] : null,
            'confidence' => $response['confidence'] ?? ($response['confidence_score'] ?? null),
            'request_json' => $request,
            'response_json' => $response,
            'error_code' => $exception instanceof AvmClientException
                ? $exception->errorCode
                : ($exception !== null ? $modelName.'_failed' : ($eligible ? null : ($response['reason'] ?? 'ineligible'))),
            'execution_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }
}
