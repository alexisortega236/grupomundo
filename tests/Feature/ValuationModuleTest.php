<?php

namespace Tests\Feature;

use App\Enums\ValuationStatus;
use App\Models\Comparable;
use App\Models\DataSource;
use App\Models\Property;
use App\Models\User;
use App\Models\Valuation;
use App\Models\ValuationComparable;
use App\Services\Valuation\AvmClient;
use App\Services\Valuation\FeatureBuilder;
use App\Services\Valuation\ValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ValuationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_valuation_property_and_failed_valuation_when_avm_is_unavailable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config(['services.avm.url' => 'https://avm.test', 'services.avm_v2.enabled' => false, 'services.avm_v2_v1.enabled' => false]);
        Http::fake(['https://avm.test/predict' => Http::response(['error' => 'down'], 500)]);

        $this->actingAs($admin)->post(route('admin.valuations.store'), $this->valuationPayload())
            ->assertRedirect();

        $property = Property::where('property_type', 'house')->first();
        $valuation = Valuation::first();

        $this->assertNotNull($property);
        $this->assertNotNull($valuation);
        $this->assertSame($admin->id, $property->user_id);
        $this->assertSame('18.8123400', $property->latitude);
        $this->assertSame('COL_13', $property->avm_colonia);
        $this->assertSame('failed', $valuation->status->value);
        $this->assertSame('avm_http_error', $valuation->error_code);
    }

    public function test_valuation_request_validates_coordinates_and_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.valuations.store'), [
            'property_type' => 'castle',
            'latitude' => 120,
            'longitude' => -200,
        ])->assertSessionHasErrors(['property_type', 'legacy_colonia', 'latitude', 'longitude']);
    }

    public function test_valuation_relationships_are_available(): void
    {
        $property = Property::factory()->create();
        $valuation = Valuation::create([
            'uuid' => (string) str()->uuid(),
            'property_id' => $property->id,
            'status' => ValuationStatus::Pending,
        ]);
        $valuation->features()->create(['features_json' => ['construction_land_ratio' => 0.75]]);
        $source = DataSource::create(['name' => 'Comparables inmobiliarios', 'type' => 'market', 'is_active' => true]);
        $comparable = Comparable::create([
            'uuid' => (string) str()->uuid(),
            'source_id' => $source->id,
            'property_type' => 'house',
            'latitude' => 18.81,
            'longitude' => -98.95,
            'listing_price' => 2500000,
            'status' => 'active',
        ]);
        ValuationComparable::create([
            'valuation_id' => $valuation->id,
            'comparable_id' => $comparable->id,
        ]);

        $this->assertTrue($property->valuations->first()->is($valuation));
        $this->assertTrue($valuation->property->is($property));
        $this->assertSame(0.75, (float) $valuation->features->features_json['construction_land_ratio']);
        $this->assertTrue($valuation->valuationComparables->first()->comparable->is($comparable));
        $this->assertTrue($source->importRuns()->get()->isEmpty());
    }

    public function test_feature_builder_creates_known_features_without_fake_enrichment(): void
    {
        $property = Property::factory()->make([
            'land_area_m2' => 200,
            'construction_area_m2' => 145,
        ]);

        $features = app(FeatureBuilder::class)->build($property);

        $this->assertSame(0.725, $features['construction_land_ratio']);
        $this->assertArrayNotHasKey('population_density', $features);
    }

    public function test_avm_client_sends_prediction_request_and_returns_valid_response(): void
    {
        config(['services.avm.url' => 'https://avm.test', 'services.avm.token' => 'secret']);
        Http::fake([
            'https://avm.test/predict' => Http::response([
                'precio_estimado' => 2980242,
                'zona_inferida' => 'media',
                'moneda' => 'MXN',
                'features_derivadas' => ['cerca_escuelas' => 1, 'cerca_transporte' => 1],
                'pois' => ['counts' => ['schools' => 1], 'nearest_m' => ['schools' => 120]],
            ]),
        ]);

        $property = Property::factory()->make([
            'property_type' => 'house',
            'land_area_m2' => 200,
            'construction_area_m2' => 145,
            'latitude' => 18.81234,
            'longitude' => -98.95412,
        ]);

        $prediction = app(AvmClient::class)->predict($property, ['legacy_colonia' => 'COL_13']);

        $this->assertSame(2980242.0, $prediction->estimatedValue);
        $this->assertSame('legacy-gcp-joblib', $prediction->modelVersion);
        $this->assertSame('media', $prediction->zoneInferred);
        Http::assertSent(fn ($request) => $request->url() === 'https://avm.test/predict'
            && $request->hasHeader('Authorization', 'Bearer secret')
            && $request['tipo'] === 'casa'
            && $request['colonia'] === 'COL_13'
            && $request['m2_terreno'] === 200
            && $request['m2_construccion'] === 145
            && $request['lat'] === 18.81234);
    }

    public function test_avm_client_handles_timeout_or_connection_failure(): void
    {
        config(['services.avm.url' => 'https://avm.test', 'services.avm_v2.enabled' => false, 'services.avm_v2_v1.enabled' => false]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->expectException(\App\Exceptions\AvmClientException::class);
        $this->expectExceptionMessage('No fue posible conectar con el microservicio AVM.');

        app(AvmClient::class)->predict(Property::factory()->make(['property_type' => 'house']), ['legacy_colonia' => 'COL_13']);
    }

    public function test_valuation_service_marks_failed_on_avm_error(): void
    {
        config(['services.avm.url' => 'https://avm.test', 'services.avm_v2.enabled' => false, 'services.avm_v2_v1.enabled' => false]);
        Http::fake(['https://avm.test/predict' => Http::response(['error' => 'down'], 500)]);

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());

        $this->assertSame('failed', $valuation->status->value);
        $this->assertSame('avm_http_error', $valuation->error_code);
        $this->assertNull($valuation->estimated_value);
    }

    public function test_valuation_service_stores_completed_prediction_without_fake_values(): void
    {
        config(['services.avm.url' => 'https://avm.test', 'services.avm_v2.enabled' => false, 'services.avm_v2_v1.enabled' => false]);
        Http::fake([
            'https://avm.test/predict' => Http::response([
                'precio_estimado' => 2980242,
                'zona_inferida' => 'media',
                'moneda' => 'MXN',
                'features_derivadas' => ['cerca_escuelas' => 1, 'cerca_transporte' => 1],
                'pois' => [
                    'cache_hit' => false,
                    'counts' => ['schools' => 2, 'bus_stops' => 1],
                    'nearest_m' => ['schools' => 100, 'bus_stops' => 80],
                    'details' => [],
                    'radius_m' => 1000,
                ],
            ]),
        ]);

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());

        $this->assertSame('completed', $valuation->status->value);
        $this->assertSame('2980242.00', $valuation->estimated_value);
        $this->assertSame('20553.39', $valuation->estimated_price_m2);
        $this->assertSame('media', $valuation->zone_inferred);
        $this->assertSame('MXN', $valuation->currency);
        $this->assertNotNull($valuation->valued_at);
        $this->assertSame(0.725, (float) $valuation->features->features_json['construction_land_ratio']);
        $this->assertSame(1, $valuation->features->features_json['avm']['cerca_escuelas']);
        $this->assertSame(2980242, $valuation->avm_response_json['precio_estimado']);
    }

    public function test_residential_v2_is_used_as_the_primary_prediction(): void
    {
        config([
            'services.avm.url' => 'https://avm.test',
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => true,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => false,
        ]);
        Http::fake([
            'https://avm.test/predict' => Http::response($this->legacySuccessResponse()),
            'https://avm.test/predict/v2/residential' => Http::response([
                'eligible' => true,
                'model' => 'avm_residential_v2_v2',
                'model_version' => 'avm_residential_v2_v2_experimental',
                'segment' => 'residential',
                'property_type' => 'house',
                'estimated_value' => 6250000,
                'currency' => 'MXN',
                'range' => ['low' => 5100000, 'high' => 7600000, 'nominal_coverage' => 0.90],
                'confidence' => 'MEDIUM',
                'location' => ['municipality' => 'Cuautla', 'ageb' => '001A'],
            ]),
        ]);

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());
        $prediction = $valuation->modelPredictions()->first();

        $this->assertSame('completed', $valuation->status->value);
        $this->assertSame('6250000.00', $valuation->estimated_value);
        $this->assertSame('5100000.00', $valuation->lower_bound);
        $this->assertSame('7600000.00', $valuation->upper_bound);
        $this->assertSame('MXN', $valuation->currency);
        $this->assertNotNull($prediction);
        $this->assertSame('avm_residential_v2', $prediction->model_name);
        $this->assertSame('completed', $prediction->status);
        $this->assertTrue($prediction->eligible);
        $this->assertSame('6250000.00', $prediction->estimated_value);
        $this->assertSame('5100000.00', $prediction->range_low);
        $this->assertSame('7600000.00', $prediction->range_high);
        $this->assertSame('MEDIUM', $prediction->confidence);
        $this->assertSame('house', $prediction->request_json['property_type']);
        $this->assertSame(6250000, $prediction->response_json['estimated_value']);
        $this->assertSame(0, $valuation->modelPredictions()->where('model_name', 'avm_v2_v1')->count());
    }

    public function test_residential_v2_failure_is_recorded_and_valuation_fails_when_fallback_disabled(): void
    {
        config([
            'services.avm.url' => 'https://avm.test',
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => true,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => false,
        ]);
        Http::fake([
            'https://avm.test/predict' => Http::response($this->legacySuccessResponse()),
            'https://avm.test/predict/v2/residential' => Http::response(['error' => 'down'], 503),
        ]);

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());
        $prediction = $valuation->modelPredictions()->first();

        $this->assertSame('failed', $valuation->status->value);
        $this->assertSame('avm_models_ineligible', $valuation->error_code);
        $this->assertNull($valuation->estimated_value);
        $this->assertNotNull($prediction);
        $this->assertSame('failed', $prediction->status);
        $this->assertFalse($prediction->eligible);
        $this->assertSame('avm_v2_http_error', $prediction->error_code);
    }

    public function test_shadow_v2_v1_coexists_with_residential_v2(): void
    {
        config([
            'services.avm.url' => 'https://avm.test',
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => true,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => true,
            'services.avm_v2_v1.url' => 'https://avm.test',
        ]);
        Http::fake([
            'https://avm.test/predict' => Http::response($this->legacySuccessResponse()),
            'https://avm.test/predict/v2/residential' => Http::response([
                'eligible' => true,
                'model_version' => 'avm_residential_v2_v2_experimental',
                'estimated_value' => 6250000,
                'range' => ['low' => 5100000, 'high' => 7600000],
                'confidence' => 'MEDIUM',
            ]),
            'https://avm.test/predict/v2/v1' => Http::response([
                'eligible' => true,
                'model_version' => 'avm_v2_v1_experimental',
                'estimated_value' => 4100000,
            ]),
        ]);

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());
        $predictions = $valuation->modelPredictions()->get()->keyBy('model_name');

        $this->assertCount(2, $predictions);
        $this->assertSame('completed', $predictions['avm_v2_v1']->status);
        $this->assertSame('4100000.00', $predictions['avm_v2_v1']->estimated_value);
        $this->assertSame('completed', $predictions['avm_residential_v2']->status);
        $this->assertSame('6250000.00', $predictions['avm_residential_v2']->estimated_value);
        $this->assertSame('6250000.00', $valuation->estimated_value);
    }

    public function test_shadow_v2_v1_failure_does_not_affect_legacy_or_v2(): void
    {
        config([
            'services.avm.url' => 'https://avm.test',
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => true,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => true,
            'services.avm_v2_v1.url' => 'https://avm.test',
        ]);
        Http::fake([
            'https://avm.test/predict' => Http::response($this->legacySuccessResponse()),
            'https://avm.test/predict/v2/residential' => Http::response(['eligible' => true, 'estimated_value' => 6250000]),
            'https://avm.test/predict/v2/v1' => Http::response(['error' => 'down'], 503),
        ]);

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());
        $predictions = $valuation->modelPredictions()->get()->keyBy('model_name');

        $this->assertSame('completed', $valuation->status->value);
        $this->assertSame('failed', $predictions['avm_v2_v1']->status);
        $this->assertSame('avm_v2_v1_http_error', $predictions['avm_v2_v1']->error_code);
        $this->assertSame('completed', $predictions['avm_residential_v2']->status);
    }

    public function test_shadow_v2_skips_land_properties(): void
    {
        config([
            'services.avm.url' => 'https://avm.test',
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => true,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => false,
        ]);
        Http::fake([
            'https://avm.test/predict' => Http::response($this->legacySuccessResponse()),
        ]);

        $valuation = app(ValuationService::class)->createAndRun([
            ...$this->valuationPayload(),
            'property_type' => 'land',
            'construction_area_m2' => null,
            'bedrooms' => null,
            'bathrooms' => null,
            'parking_spaces' => null,
        ], User::factory()->create());

        $this->assertSame('completed', $valuation->status->value);
        $this->assertSame(0, $valuation->modelPredictions()->count());
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/predict/v2/residential'));
    }

    public function test_residential_ineligible_falls_back_to_v2_v1_without_duplicate_execution(): void
    {
        config([
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => false,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => true,
            'services.avm_v2_v1.url' => 'https://avm.test',
        ]);
        Http::fake([
            'https://avm.test/predict/v2/residential' => Http::response(['eligible' => false, 'reason' => 'insufficient_data']),
            'https://avm.test/predict/v2/v1' => Http::response([
                'eligible' => true,
                'model' => 'avm_v2_v1',
                'model_version' => 'avm_v2_v1_2026',
                'estimated_value' => 2249374,
                'range' => ['low' => 2000000, 'high' => 2500000],
                'currency' => 'MXN',
            ]),
        ]);

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());
        $predictions = $valuation->modelPredictions()->get()->keyBy('model_name');

        $this->assertSame('completed', $valuation->status->value);
        $this->assertSame('avm_v2_v1_2026', $valuation->modelVersion->version);
        $this->assertSame('2249374.00', $valuation->estimated_value);
        $this->assertSame('ineligible', $predictions['avm_residential_v2']->status);
        $this->assertSame('completed', $predictions['avm_v2_v1']->status);
        $this->assertCount(2, $predictions);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/predict'));
    }

    public function test_residential_exception_falls_back_to_v2_v1(): void
    {
        config([
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => false,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => true,
            'services.avm_v2_v1.url' => 'https://avm.test',
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/predict/v2/residential')) {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            }

            return Http::response(['eligible' => true, 'estimated_value' => 1800000, 'model_version' => 'v1']);
        });

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());
        $predictions = $valuation->modelPredictions()->get()->keyBy('model_name');

        $this->assertSame('completed', $valuation->status->value);
        $this->assertSame('1800000.00', $valuation->estimated_value);
        $this->assertSame('failed', $predictions['avm_residential_v2']->status);
        $this->assertSame('avm_v2_connection_failed', $predictions['avm_residential_v2']->error_code);
        $this->assertSame('completed', $predictions['avm_v2_v1']->status);
    }

    public function test_both_new_models_ineligible_mark_valuation_failed_with_context(): void
    {
        config([
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => false,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => true,
            'services.avm_v2_v1.url' => 'https://avm.test',
        ]);
        Http::fake([
            'https://avm.test/predict/v2/residential' => Http::response(['eligible' => false, 'reason' => 'no_residential_model']),
            'https://avm.test/predict/v2/v1' => Http::response(['eligible' => false, 'reason' => 'outside_coverage']),
        ]);

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());

        $this->assertSame('failed', $valuation->status->value);
        $this->assertSame('avm_models_ineligible', $valuation->error_code);
        $this->assertStringContainsString('outside_coverage', $valuation->error_message);
        $this->assertCount(2, $valuation->modelPredictions);
    }

    public function test_both_new_models_failing_are_captured_without_bubbling_exception(): void
    {
        config([
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => false,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => true,
            'services.avm_v2_v1.url' => 'https://avm.test',
        ]);
        Http::fake([
            'https://avm.test/predict/v2/residential' => Http::response(['error' => 'down'], 503),
            'https://avm.test/predict/v2/v1' => Http::response(['error' => 'down'], 503),
        ]);

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());
        $predictions = $valuation->modelPredictions()->get()->keyBy('model_name');

        $this->assertSame('failed', $valuation->status->value);
        $this->assertSame('avm_models_ineligible', $valuation->error_code);
        $this->assertSame('failed', $predictions['avm_residential_v2']->status);
        $this->assertSame('failed', $predictions['avm_v2_v1']->status);
        $this->assertSame('avm_v2_v1_http_error', $predictions['avm_v2_v1']->error_code);
    }

    public function test_land_uses_only_v2_v1_as_primary_model(): void
    {
        config([
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => true,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => true,
            'services.avm_v2_v1.url' => 'https://avm.test',
        ]);
        Http::fake(['https://avm.test/predict/v2/v1' => Http::response([
            'eligible' => true,
            'estimated_value' => 900000,
            'model_version' => 'land-v1',
        ])]);

        $valuation = app(ValuationService::class)->createAndRun([
            ...$this->valuationPayload(),
            'property_type' => 'land',
            'construction_area_m2' => null,
            'bedrooms' => null,
            'bathrooms' => null,
            'parking_spaces' => null,
        ], User::factory()->create());

        $this->assertSame('completed', $valuation->status->value);
        $this->assertSame('900000.00', $valuation->estimated_value);
        $this->assertSame(['avm_v2_v1'], $valuation->modelPredictions->pluck('model_name')->all());
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/predict/v2/residential'));
    }

    public function test_cdmx_hybrid_model_response_is_used_as_primary(): void
    {
        config([
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => false,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => true,
            'services.avm_v2_v1.url' => 'https://avm.test',
        ]);
        Http::fake(['https://avm.test/predict/v2/residential' => Http::response([
            'eligible' => true,
            'model' => 'avm_cdmx_v2_1_hybrid',
            'model_version' => 'cdmx_v2_1',
            'estimated_value' => 4200000,
            'currency' => 'MXN',
            'range' => ['low' => 3800000, 'high' => 4600000],
        ])]);

        $valuation = app(ValuationService::class)->createAndRun($this->valuationPayload(), User::factory()->create());

        $this->assertSame('completed', $valuation->status->value);
        $this->assertSame('cdmx_v2_1', $valuation->modelVersion->version);
        $this->assertSame('avm_cdmx_v2_1_hybrid', $valuation->avm_response_json['model']);
        $this->assertSame(1, $valuation->modelPredictions()->count());
    }

    public function test_admin_detail_shows_experimental_model_predictions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create();
        $valuation = Valuation::create([
            'uuid' => (string) str()->uuid(),
            'property_id' => $property->id,
            'status' => ValuationStatus::Completed,
            'estimated_value' => 2980242,
            'currency' => 'MXN',
        ]);
        $valuation->modelPredictions()->create([
            'model_name' => 'avm_residential_v2',
            'model_version' => 'avm_residential_v2_v2_experimental',
            'status' => 'completed',
            'eligible' => true,
            'estimated_value' => 6250000,
            'range_low' => 5100000,
            'range_high' => 7600000,
            'confidence' => 'MEDIUM',
        ]);

        $this->actingAs($admin)->get(route('admin.valuations.show', $valuation))
            ->assertOk()
            ->assertSee('Predicciones del modelo')
            ->assertSee('Comparación de modelos')
            ->assertSee('Legacy')
            ->assertSee('avm_residential_v2');
    }

    private function valuationPayload(): array
    {
        return [
            'property_type' => 'house',
            'legacy_colonia' => 'COL_13',
            'latitude' => 18.81234,
            'longitude' => -98.95412,
            'land_area_m2' => 200,
            'construction_area_m2' => 145,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'parking_spaces' => 2,
            'property_age_years' => 8,
            'postal_code' => '62740',
            'neighborhood' => 'Centro',
            'locality' => 'Cuautla',
            'municipality' => 'Cuautla',
            'state' => 'Morelos',
        ];
    }

    private function legacySuccessResponse(): array
    {
        return [
            'precio_estimado' => 2980242,
            'zona_inferida' => 'media',
            'moneda' => 'MXN',
            'features_derivadas' => ['cerca_escuelas' => 1, 'cerca_transporte' => 1],
            'pois' => [
                'cache_hit' => false,
                'counts' => ['schools' => 2, 'bus_stops' => 1],
                'nearest_m' => ['schools' => 100, 'bus_stops' => 80],
                'details' => [],
                'radius_m' => 1000,
            ],
        ];
    }
}
