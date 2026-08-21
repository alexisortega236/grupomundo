<?php

namespace Tests\Feature;

use App\Models\PostalSettlement;
use Database\Seeders\CdmxPostalSettlementsSeeder;
use Database\Seeders\MorelosPostalSettlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CdmxPostalSettlementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cdmx_snapshot_covers_sixteen_alcaldias_and_is_idempotent(): void
    {
        $this->seed(CdmxPostalSettlementsSeeder::class);
        $this->seed(CdmxPostalSettlementsSeeder::class);

        // SEPOMEX has 1,531 rows; four repeat the same CP/asentamiento identity.
        $this->assertSame(1527, PostalSettlement::where('state', 'Ciudad de México')->count());
        $this->assertSame(
            1509,
            PostalSettlement::query()
                ->where('state', 'Ciudad de México')
                ->select(['municipality', 'settlement'])
                ->groupBy('municipality', 'settlement')
                ->get()
                ->count()
        );
        $this->assertSame(16, PostalSettlement::where('state', 'Ciudad de México')->distinct('municipality')->count('municipality'));
        $this->assertSame(
            1527,
            PostalSettlement::query()
                ->where('state', 'Ciudad de México')
                ->select(['source', 'state_code', 'municipality_code', 'settlement', 'postal_code'])
                ->groupBy('source', 'state_code', 'municipality_code', 'settlement', 'postal_code')
                ->get()
                ->count()
        );
    }

    public function test_cdmx_locations_and_del_valle_autocomplete_use_catalog(): void
    {
        $this->seed(CdmxPostalSettlementsSeeder::class);

        $this->getJson('/valuador/locations/municipalities?state=Ciudad%20de%20M%C3%A9xico')
            ->assertOk()
            ->assertJsonCount(16)
            ->assertJsonFragment(['Benito Juárez']);

        $this->getJson('/valuador/locations/settlements?state=Ciudad%20de%20M%C3%A9xico&municipality=Benito%20Ju%C3%A1rez&q=Del')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Del Valle Centro', 'postal_code' => '03100']);
    }

    public function test_a_settlement_cannot_be_used_across_states(): void
    {
        $this->seed(CdmxPostalSettlementsSeeder::class);
        $this->seed(MorelosPostalSettlementsSeeder::class);
        $settlement = PostalSettlement::where('state', 'Ciudad de México')->where('municipality', 'Benito Juárez')->firstOrFail();

        $this->getJson('/valuador/geocode?state=Morelos&municipality=Cuautla&postal_settlement_id='.$settlement->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selecciona una colonia válida dentro del municipio elegido.');
    }

    public function test_cdmx_geocode_and_public_valuation_use_the_selected_settlement(): void
    {
        $this->seed(CdmxPostalSettlementsSeeder::class);
        $settlement = PostalSettlement::where('state', 'Ciudad de México')
            ->where('municipality', 'Benito Juárez')
            ->where('settlement', 'Del Valle Centro')
            ->firstOrFail();

        config([
            'services.nominatim.url' => 'https://nominatim.test',
            'services.avm_v2.enabled' => false,
            'services.avm_v2_v1.enabled' => false,
        ]);
        Http::fake([
            'https://nominatim.test/search*' => Http::response([[
                'lat' => '19.3860',
                'lon' => '-99.1680',
                'display_name' => 'Del Valle Centro, Benito Juárez, Ciudad de México, México',
                'address' => [
                    'country_code' => 'mx',
                    'country' => 'México',
                    'state' => 'Ciudad de México',
                    'county' => 'Benito Juárez',
                    'neighbourhood' => 'Del Valle Centro',
                    'postcode' => '03100',
                ],
            ]]),
        ]);

        $this->getJson('/valuador/geocode?state=Ciudad%20de%20M%C3%A9xico&municipality=Benito%20Ju%C3%A1rez&postal_settlement_id='.$settlement->id)
            ->assertOk()
            ->assertJsonPath('location.state', 'Ciudad de México')
            ->assertJsonPath('location.municipality', 'Benito Juárez')
            ->assertJsonPath('location.postal_code', '03100');

        $response = $this->post(route('valuation.store'), [
            'property_type' => 'apartment',
            'state' => 'Ciudad de México',
            'municipality' => 'Benito Juárez',
            'neighborhood' => 'Del Valle Centro',
            'postal_settlement_id' => $settlement->id,
            'postal_code' => '03100',
            'latitude' => '19.3860000',
            'longitude' => '-99.1680000',
            'location_source' => 'sepomex_geocoded',
            'location_precision' => 'neighborhood',
            'land_area_m2' => '',
            'construction_area_m2' => 80,
            'bedrooms' => 2,
            'bathrooms' => 2,
            'parking_spaces' => 1,
            'property_age_years' => 5,
        ]);

        $response->assertRedirect();
        $this->assertSame('Ciudad de México', \App\Models\Property::first()->state);
        $this->assertSame('Benito Juárez', \App\Models\Property::first()->municipality);
        $this->assertSame('03100', \App\Models\Property::first()->postal_code);
    }

    public function test_cdmx_location_selection_is_restored_after_an_unrelated_validation_error(): void
    {
        $this->seed(CdmxPostalSettlementsSeeder::class);
        $settlement = PostalSettlement::where('state', 'Ciudad de México')
            ->where('municipality', 'Benito Juárez')
            ->where('settlement', 'Del Valle Centro')
            ->firstOrFail();

        $response = $this->from('/valuador')->post(route('valuation.store'), [
            'property_type' => 'apartment',
            'state' => 'Ciudad de México',
            'municipality' => 'Benito Juárez',
            'neighborhood' => 'Del Valle Centro',
            'postal_settlement_id' => $settlement->id,
            'postal_code' => '03100',
            'latitude' => '19.3860000',
            'longitude' => '-99.1680000',
            'location_precision' => 'neighborhood',
            'construction_area_m2' => '',
            'bedrooms' => 2,
            'bathrooms' => 2,
            'parking_spaces' => 1,
        ]);

        $response->assertRedirect('/valuador')->assertSessionHasErrors('construction_area_m2');
        $response->assertSessionHas('_old_input.state', 'Ciudad de México');
        $response->assertSessionHas('_old_input.municipality', 'Benito Juárez');
        $response->assertSessionHas('_old_input.neighborhood', 'Del Valle Centro');
        $response->assertSessionHas('_old_input.postal_settlement_id', $settlement->id);
        $response->assertSessionHas('_old_input.postal_code', '03100');

        $this->withSession($response->getSession()->all())
            ->get('/valuador')
            ->assertSee('value="'.$settlement->id.'"', false)
            ->assertSee('value="Del Valle Centro"', false)
            ->assertSee('value="03100"', false)
            ->assertSee('value="Ciudad de México"', false)
            ->assertSee('selectedNeighborhood', false)
            ->assertSee('query === selectedNeighborhood', false);
    }

    public function test_cdmx_production_payload_includes_canonical_neighborhood_for_el_rosario(): void
    {
        $this->seed(CdmxPostalSettlementsSeeder::class);
        $settlement = PostalSettlement::where('state', 'Ciudad de México')
            ->where('municipality', 'Azcapotzalco')
            ->where('settlement', 'El Rosario')
            ->where('postal_code', '02100')
            ->firstOrFail();

        config([
            'services.nominatim.url' => 'https://nominatim.test',
            'services.avm_v2.enabled' => true,
            'services.avm_v2.shadow_mode' => true,
            'services.avm_v2.url' => 'https://avm.test',
            'services.avm_v2_v1.enabled' => false,
        ]);
        Http::fake([
            'https://nominatim.test/search*' => Http::response([[
                'lat' => '19.5045485',
                'lon' => '-99.1997919',
                'display_name' => 'El Rosario, Azcapotzalco, Ciudad de México, México',
                'address' => [
                    'country_code' => 'mx',
                    'state' => 'Ciudad de México',
                    'county' => 'Azcapotzalco',
                    'neighbourhood' => 'El Rosario',
                    'postcode' => '02100',
                ],
            ]]),
            'https://avm.test/predict/v2/residential' => Http::response([
                'eligible' => true,
                'model_version' => 'avm_cdmx_v2_1_hybrid',
                'estimated_value' => 3429473,
                'currency' => 'MXN',
                'range' => ['low' => 2918919, 'high' => 3761379],
                'confidence' => 'MEDIUM',
            ]),
        ]);

        $response = $this->post(route('valuation.store'), [
            'state' => 'Ciudad de México',
            'municipality' => 'Azcapotzalco',
            'neighborhood' => 'El Rosario',
            'postal_settlement_id' => $settlement->id,
            'postal_code' => '02100',
            'latitude' => '19.5045485',
            'longitude' => '-99.1997919',
            'location_precision' => 'neighborhood',
            'location_source' => 'sepomex_geocoded',
            'property_type' => 'apartment',
            'construction_area_m2' => 80,
            'bedrooms' => 2,
            'bathrooms' => 2,
            'parking_spaces' => 1,
        ]);

        $response->assertRedirect();
        $this->assertSame('Azcapotzalco', \App\Models\Property::first()->municipality);
        $this->assertSame('El Rosario', \App\Models\Property::first()->neighborhood);
        $this->assertSame('02100', \App\Models\Property::first()->postal_code);
        $this->assertStringNotContainsString(
            'Selecciona una colonia válida dentro del municipio elegido.',
            implode(' ', session()->get('errors', collect())->all())
        );
        Http::assertSent(fn ($request) => $request->url() === 'https://avm.test/predict/v2/residential'
            && $request['neighborhood'] === 'El Rosario'
            && $request['property_type'] === 'apartment');
    }

    public function test_form_exposes_canonical_location_field_names_and_restores_cdmx_municipality(): void
    {
        $this->seed(CdmxPostalSettlementsSeeder::class);

        $response = $this->withSession([
            '_old_input' => [
                'state' => 'Ciudad de México',
                'municipality' => 'Azcapotzalco',
                'neighborhood' => 'El Rosario',
                'postal_settlement_id' => 1958,
                'postal_code' => '02100',
            ],
        ])->get('/valuador')
            ->assertOk()
            ->assertSee('id="valuation-form"', false)
            ->assertSee('name="state"', false)
            ->assertSee('id="municipality" name="municipality"', false)
            ->assertSee('type="submit"', false)
            ->assertSee('name="neighborhood"', false)
            ->assertSee('name="postal_settlement_id"', false)
            ->assertSee('value="Azcapotzalco"', false)
            ->assertSee('const loadMunicipalities = async', false)
            ->assertSee('const selectSettlement = async', false)
            ->assertSee('const restoreInitialLocation = async', false)
            ->assertSee('await loadMunicipalities(state.value, reverse.municipality ||', false)
            ->assertSee("console.debug('[valuation] municipalities request'", false)
            ->assertSee("console.debug('[valuation] municipalities response'", false)
            ->assertSee("console.debug('[valuation] municipalities data'", false)
            ->assertSee('if (!Array.isArray(data))', false)
            ->assertSee('} finally {', false)
            ->assertSee('error.name === \'AbortError\'', false)
            ->assertDontSee('municipality-value', false)
            ->assertDontSee("form.addEventListener('formdata'", false);

        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $forms = $xpath->query('//form[@id="valuation-form"]');
        $municipalities = $xpath->query('//*[@id="municipality"]');
        $municipality = $municipalities->item(0);

        $this->assertSame(1, $forms->length);
        $this->assertSame(1, $municipalities->length);
        $this->assertSame(1, $xpath->query('//*[@name="municipality"]')->length);
        $this->assertNotNull($municipality);
        $this->assertSame('municipality', $municipality->getAttribute('name'));
        $selectedOption = $xpath->query('//select[@id="municipality"]/option[@selected]')->item(0);
        $this->assertNotNull($selectedOption);
        $this->assertSame('Azcapotzalco', $selectedOption->getAttribute('value'));
        $this->assertFalse($municipality->hasAttribute('disabled'));
        $this->assertSame(1, $xpath->query('//form[@id="valuation-form"]//select[@id="municipality"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="municipality-value"]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="valuation-form"]//button[@type="submit"]')->length);
    }
}
