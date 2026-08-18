<?php

namespace Tests\Feature;

use App\Enums\ValuationStatus;
use App\Models\Property;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicValuationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_valuator_page_uses_real_location_and_hides_legacy_zones(): void
    {
        $this->get('/valuador')
            ->assertOk()
            ->assertSee('Conoce el valor estimado de tu propiedad')
            ->assertSee('Ubicación de la propiedad')
            ->assertSee('Municipio')
            ->assertSee('Colonia / Fraccionamiento')
            ->assertSee('Usar mi ubicación')
            ->assertSee('valuation-latitude')
            ->assertSee('valuation-longitude')
            ->assertSee('Calcular valor estimado')
            ->assertDontSee('valuation-map')
            ->assertDontSee('leaflet')
            ->assertDontSee('Zona 13')
            ->assertDontSee('Colonia 13 - zona media')
            ->assertDontSee('COL_13')
            ->assertDontSee('AGEB')
            ->assertDontSee('DENUE')
            ->assertDontSee('Latitud')
            ->assertDontSee('Longitud')
            ->assertDontSee('colonia_ref')
            ->assertDontSee(config('services.avm.url'))
            ->assertDontSee('mousemove', false)
            ->assertDontSee('pointermove', false)
            ->assertDontSee('mouseover', false);

    }

    public function test_manual_location_endpoint_returns_approximate_coordinates(): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::response([[
                'lat' => '18.9500',
                'lon' => '-99.2300',
                'display_name' => 'Maravillas, Cuernavaca, Morelos, México',
                'address' => [
                    'state' => 'Morelos',
                    'county' => 'Municipio de Cuernavaca',
                    'city' => 'Cuernavaca',
                    'neighbourhood' => 'Maravillas',
                ],
            ]]),
        ]);
        config(['services.nominatim.url' => 'https://nominatim.test']);

        $this->getJson(route('valuation.geocode', [
            'state' => 'Morelos',
            'municipality' => 'Cuernavaca',
            'neighborhood' => 'Maravillas',
        ]))->assertOk()
            ->assertJsonPath('location.municipality', 'Cuernavaca')
            ->assertJsonPath('location.location_source', 'manual_geocode')
            ->assertJsonPath('location.location_precision', 'neighborhood')
            ->assertJsonPath('location.latitude', 18.95);
    }

    public function test_reverse_location_endpoint_returns_device_location(): void
    {
        Http::fake([
            'https://nominatim.test/reverse*' => Http::response([
                'lat' => '18.8123',
                'lon' => '-98.9556',
                'address' => [
                    'state' => 'Morelos',
                    'county' => 'Municipio de Cuautla',
                    'city' => 'Cuautla',
                    'neighbourhood' => 'Centro',
                ],
            ]),
        ]);
        config(['services.nominatim.url' => 'https://nominatim.test']);

        $this->getJson(route('valuation.reverse-geocode', ['latitude' => 18.8123, 'longitude' => -98.9556]))
            ->assertOk()
            ->assertJsonPath('location.municipality', 'Cuautla')
            ->assertJsonPath('location.neighborhood', 'Centro')
            ->assertJsonPath('location.location_source', 'device')
            ->assertJsonPath('location.location_precision', 'device');
    }

    public function test_public_valuator_renders_type_cards_and_conditional_field_rules(): void
    {
        $this->get('/valuador')
            ->assertOk()
            ->assertSee('¿Qué tipo de propiedad deseas valuar?')
            ->assertSee('Casa')
            ->assertSee('Departamento')
            ->assertSee('Terreno')
            ->assertSee("propertyType === 'house' || propertyType === 'land'", false)
            ->assertSee("propertyType === 'house' || propertyType === 'apartment'", false)
            ->assertSee('Superficie de terreno')
            ->assertSee('Superficie de construcción')
            ->assertSee('Recámaras')
            ->assertSee('Baños')
            ->assertSee('Estacionamientos')
            ->assertSee('Antigüedad aproximada');
    }

    public function test_public_valuator_validates_real_location_fields(): void
    {
        $this->post(route('valuation.store'), [
            'property_type' => 'commercial',
            'municipality' => 'Municipio inventado',
            'neighborhood' => '',
            'latitude' => '',
            'longitude' => '',
        ])->assertSessionHasErrors(['property_type', 'municipality', 'neighborhood', 'latitude', 'longitude', 'land_area_m2', 'construction_area_m2']);
    }

    public function test_public_valuation_stores_real_location_and_does_not_send_unverified_legacy_fallback(): void
    {
        config([
            'services.avm.url' => 'https://avm.test',
            'services.avm_v2.enabled' => false,
        ]);
        Http::fake();

        $response = $this->post(route('valuation.store'), $this->payload())
            ->assertRedirect();

        $valuation = Valuation::with('property')->first();
        $response->assertRedirect(route('valuation.show', $valuation->uuid));

        $this->assertSame('public', $valuation->source);
        $this->assertSame('failed', $valuation->status->value);
        $this->assertSame('missing_legacy_colonia', $valuation->error_code);
        $this->assertNull($valuation->estimated_value);
        $this->assertNull($valuation->property->avm_colonia);
        $this->assertSame('Centro', $valuation->property->neighborhood);
        $this->assertSame('Cuautla', $valuation->property->municipality);
        $this->assertSame('Cuautla', $valuation->property->locality);
        $this->assertSame('Morelos', $valuation->property->state);
        $this->assertSame('18.8123000', $valuation->property->latitude);
        $this->assertSame('-98.9556000', $valuation->property->longitude);
        $this->assertSame('manual_geocode', $valuation->property->location_source);
        $this->assertSame('neighborhood', $valuation->property->location_precision);

        Http::assertNothingSent();
    }

    public function test_public_shadow_v2_receives_captured_coordinates_even_when_legacy_mapping_is_unavailable(): void
    {
        config([
            'services.avm.url' => 'https://avm.test',
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => true,
            'services.avm_v2.url' => 'https://avm.test',
        ]);
        Http::fake([
            'https://avm.test/predict/v2/residential' => Http::response([
                'eligible' => true,
                'model' => 'avm_residential_v2_v2',
                'model_version' => 'avm_residential_v2_v2_experimental',
                'segment' => 'residential',
                'property_type' => 'house',
                'estimated_value' => 931920,
                'currency' => 'MXN',
                'range' => ['low' => 700000, 'high' => 1200000, 'nominal_coverage' => 0.9],
                'confidence' => 'MEDIUM',
                'location' => ['municipality' => 'Cuautla', 'locality' => 'Cuautla', 'ageb' => '057A'],
            ]),
        ]);

        $this->post(route('valuation.store'), $this->payload())->assertRedirect();

        $valuation = Valuation::with(['property', 'modelPredictions'])->first();
        $prediction = $valuation->modelPredictions->first();

        $this->assertSame('failed', $valuation->status->value);
        $this->assertSame('missing_legacy_colonia', $valuation->error_code);
        $this->assertNotNull($prediction);
        $this->assertSame('completed', $prediction->status);
        $this->assertSame('931920.00', $prediction->estimated_value);
        $this->assertSame(18.8123, $prediction->request_json['latitude']);
        $this->assertSame(-98.9556, $prediction->request_json['longitude']);

        Http::assertSent(fn ($request) => $request->url() === 'https://avm.test/predict/v2/residential'
            && $request['property_type'] === 'house'
            && $request['latitude'] === 18.8123
            && $request['longitude'] === -98.9556);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://avm.test/predict'
            || (($request['colonia'] ?? null) === 'COL_13'));
    }

    public function test_public_shadow_v2_failure_does_not_replace_legacy_controlled_error(): void
    {
        config([
            'services.avm.url' => 'https://avm.test',
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => true,
            'services.avm_v2.url' => 'https://avm.test',
        ]);
        Http::fake([
            'https://avm.test/predict/v2/residential' => Http::response(['error' => 'down'], 503),
        ]);

        $this->post(route('valuation.store'), $this->payload())->assertRedirect();

        $valuation = Valuation::with('modelPredictions')->first();
        $prediction = $valuation->modelPredictions->first();

        $this->assertSame('failed', $valuation->status->value);
        $this->assertSame('missing_legacy_colonia', $valuation->error_code);
        $this->assertNotNull($prediction);
        $this->assertSame('failed', $prediction->status);
        $this->assertSame('avm_v2_http_error', $prediction->error_code);
    }

    public function test_public_result_shows_human_location_and_hides_technical_model_data(): void
    {
        Http::fake();
        $this->post(route('valuation.store'), $this->payload())->assertRedirect();
        $valuation = Valuation::first();

        $this->get(route('valuation.show', $valuation->uuid))
            ->assertOk()
            ->assertSee('Características consideradas')
            ->assertSee('Ubicación analizada')
            ->assertSee('Centro')
            ->assertSee('Cuautla')
            ->assertSee('Morelos')
            ->assertSee('Hablar con un asesor')
            ->assertDontSee('Zona 13')
            ->assertDontSee('Zona de referencia')
            ->assertDontSee('COL_13')
            ->assertDontSee('AGEB')
            ->assertDontSee('DENUE')
            ->assertDontSee('18.8123')
            ->assertDontSee('-98.9556')
            ->assertDontSee('features_derivadas')
            ->assertDontSee('factor_colonia');
    }

    public function test_public_result_uses_valid_v2_prediction_when_flag_is_enabled(): void
    {
        config(['services.avm_v2.public_result' => true]);
        $valuation = $this->storedValuationWithPrediction(
            legacyValue: 2980242,
            prediction: [
                'status' => 'completed',
                'eligible' => true,
                'estimated_value' => 931920,
                'range_low' => 700000,
                'range_high' => 1200000,
                'confidence' => 'MEDIUM',
                'response_json' => ['location' => ['municipality' => 'Cuautla', 'ageb' => '057A']],
            ],
        );

        $this->get(route('valuation.show', $valuation->uuid))
            ->assertOk()
            ->assertSee('$931,920 MXN')
            ->assertSee('Rango estimado: $700,000 - $1,200,000 MXN')
            ->assertDontSee('$2,980,242 MXN')
            ->assertDontSee('avm_residential_v2')
            ->assertDontSee('MEDIUM')
            ->assertDontSee('057A')
            ->assertDontSee('AGEB')
            ->assertDontSee('DENUE');

        $this->assertSame('2980242.00', $valuation->fresh()->estimated_value);
    }

    public function test_public_result_ignores_v2_when_flag_is_disabled(): void
    {
        config(['services.avm_v2.public_result' => false]);
        $valuation = $this->storedValuationWithPrediction(
            legacyValue: 2980242,
            prediction: [
                'status' => 'completed',
                'eligible' => true,
                'estimated_value' => 931920,
                'range_low' => 700000,
                'range_high' => 1200000,
            ],
        );

        $this->get(route('valuation.show', $valuation->uuid))
            ->assertOk()
            ->assertSee('$2,980,242 MXN')
            ->assertDontSee('$931,920 MXN')
            ->assertDontSee('Rango estimado');
    }

    public function test_public_result_falls_back_to_legacy_when_v2_failed_or_ineligible(): void
    {
        config(['services.avm_v2.public_result' => true]);
        $failed = $this->storedValuationWithPrediction(
            legacyValue: 2980242,
            prediction: ['status' => 'failed', 'eligible' => false, 'estimated_value' => null],
        );
        $ineligible = $this->storedValuationWithPrediction(
            legacyValue: 1569135,
            prediction: ['status' => 'ineligible', 'eligible' => false, 'estimated_value' => null],
        );

        $this->get(route('valuation.show', $failed->uuid))
            ->assertOk()
            ->assertSee('$2,980,242 MXN')
            ->assertDontSee('Rango estimado');
        $this->get(route('valuation.show', $ineligible->uuid))
            ->assertOk()
            ->assertSee('$1,569,135 MXN');
    }

    public function test_land_never_uses_residential_v2_public_result(): void
    {
        config(['services.avm_v2.public_result' => true]);
        $valuation = $this->storedValuationWithPrediction(
            legacyValue: 2980242,
            propertyType: 'land',
            prediction: [
                'status' => 'completed',
                'eligible' => true,
                'estimated_value' => 931920,
                'range_low' => 700000,
                'range_high' => 1200000,
            ],
        );

        $this->get(route('valuation.show', $valuation->uuid))
            ->assertOk()
            ->assertSee('La valuación automática de terrenos todavía no está disponible')
            ->assertDontSee('$931,920 MXN')
            ->assertDontSee('$2,980,242 MXN');
    }

    public function test_admin_valuations_still_list_public_valuations(): void
    {
        Http::fake();
        $this->post(route('valuation.store'), $this->payload())->assertRedirect();

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.valuations.index'))
            ->assertOk()
            ->assertSee('Casa')
            ->assertSee('Fallida');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'property_type' => 'house',
            'municipality' => 'Cuautla',
            'neighborhood' => 'Centro',
            'state' => 'Morelos',
            'latitude' => 18.8123,
            'longitude' => -98.9556,
            'location_source' => 'manual_geocode',
            'location_precision' => 'neighborhood',
            'land_area_m2' => 200,
            'construction_area_m2' => 160,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'parking_spaces' => 2,
            'property_age_years' => 8,
        ], $overrides);
    }

    private function storedValuationWithPrediction(float $legacyValue, array $prediction, string $propertyType = 'house'): Valuation
    {
        $property = Property::factory()->create([
            'property_type' => $propertyType,
            'neighborhood' => 'Centro',
            'municipality' => 'Cuautla',
            'city' => 'Cuautla',
            'state' => 'Morelos',
            'latitude' => 18.8123,
            'longitude' => -98.9556,
            'land_area_m2' => 200,
            'construction_area_m2' => $propertyType === 'land' ? null : 160,
            'bedrooms' => $propertyType === 'land' ? null : 3,
            'bathrooms' => $propertyType === 'land' ? null : 2,
            'parking_spaces' => $propertyType === 'land' ? null : 2,
            'property_age_years' => $propertyType === 'land' ? null : 8,
        ]);
        $valuation = Valuation::create([
            'uuid' => (string) str()->uuid(),
            'property_id' => $property->id,
            'source' => 'public',
            'status' => ValuationStatus::Completed,
            'estimated_value' => $legacyValue,
            'currency' => 'MXN',
            'valued_at' => now(),
        ]);
        $valuation->modelPredictions()->create([
            'model_name' => 'avm_residential_v2',
            'model_version' => 'avm_residential_v2_v2_experimental',
            'status' => $prediction['status'],
            'eligible' => $prediction['eligible'],
            'estimated_value' => $prediction['estimated_value'] ?? null,
            'range_low' => $prediction['range_low'] ?? null,
            'range_high' => $prediction['range_high'] ?? null,
            'confidence' => $prediction['confidence'] ?? null,
            'request_json' => ['latitude' => 18.8123, 'longitude' => -98.9556],
            'response_json' => $prediction['response_json'] ?? [],
        ]);

        return $valuation;
    }
}
