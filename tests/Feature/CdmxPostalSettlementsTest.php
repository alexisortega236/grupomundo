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
}
