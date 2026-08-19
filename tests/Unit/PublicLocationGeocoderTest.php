<?php

namespace Tests\Unit;

use App\Services\Valuation\PublicLocationGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PublicLocationGeocoderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.nominatim.url' => 'https://nominatim.test',
            'services.nominatim.user_agent' => 'GrupoMundoPatrimonialTest/1.0',
        ]);
    }

    public function test_city_is_accepted_as_the_municipality(): void
    {
        Http::fake(['https://nominatim.test/search*' => Http::response([$this->place([
            'city' => 'Cuautla',
            'neighbourhood' => 'Año de Juárez',
        ])])]);

        $location = app(PublicLocationGeocoder::class)->geocode('Morelos', 'Cuautla', 'Año de Juárez', '62748');

        $this->assertSame('Cuautla', $location['municipality']);
        $this->assertSame('Año de Juárez', $location['neighborhood']);
    }

    public function test_town_county_and_municipality_keys_are_supported(): void
    {
        foreach (['town' => 'Cuautla', 'county' => 'Cuautla', 'municipality' => 'Cuautla'] as $key => $value) {
            Cache::flush();
            Http::fake(['https://nominatim.test/search*' => Http::response([$this->place([
                $key => $value,
                'suburb' => 'Año de Juárez',
            ])])]);

            $location = app(PublicLocationGeocoder::class)->geocode('Morelos', 'Cuautla', 'Año de Juárez', '62748');

            $this->assertSame('Cuautla', $location['municipality'], $key);
            $this->assertSame('Año de Juárez', $location['neighborhood'], $key);
        }
    }

    public function test_neighbourhood_and_postal_code_are_used_for_precision(): void
    {
        Http::fake(['https://nominatim.test/search*' => Http::response([$this->place([
            'city' => 'Cuautla',
            'neighbourhood' => 'Año de Juárez',
            'postcode' => '62748',
        ])])]);

        $location = app(PublicLocationGeocoder::class)->geocode('Morelos', 'Cuautla', 'Año de Juárez', '62748');

        $this->assertSame('neighborhood', $location['location_precision']);
        $this->assertSame('62748', $location['postal_code']);
    }

    public function test_multiple_results_select_the_best_named_match(): void
    {
        Http::fake(['https://nominatim.test/search*' => Http::response([
            $this->place(['city' => 'Cuautla', 'neighbourhood' => null, 'suburb' => 'Centro', 'display_name' => 'Centro, Cuautla, Morelos, México', 'lat' => '18.81']),
            $this->place(['city' => 'Cuautla', 'suburb' => 'Año de Juárez', 'lat' => '18.8123']),
        ])]);

        $location = app(PublicLocationGeocoder::class)->geocode('Morelos', 'Cuautla', 'Año de Juárez', '62748');

        $this->assertSame(18.8123, $location['latitude']);
        $this->assertSame('Año de Juárez', $location['neighborhood']);
    }

    public function test_it_tries_the_five_queries_in_order(): void
    {
        $queries = [];
        Http::fake(function (Request $request) use (&$queries) {
            $queries[] = $request->data()['q'] ?? '';

            return Http::response([]);
        });

        try {
            app(PublicLocationGeocoder::class)->geocode('Morelos', 'Cuautla', 'Año de Juárez', '62748');
            $this->fail('Expected geocoding to fail without results.');
        } catch (RuntimeException) {
            // Expected controlled failure.
        }

        $this->assertSame([
            'Año de Juárez, Cuautla, Morelos, 62748, México',
            'Año de Juárez, Cuautla, Morelos, México',
            'Año de Juárez, 62748, Morelos, México',
            '62748, Cuautla, Morelos, México',
            'Año de Juárez, Morelos, México',
        ], $queries);
    }

    public function test_result_outside_morelos_is_rejected(): void
    {
        Http::fake(['https://nominatim.test/search*' => Http::response([$this->place([
            'state' => 'Puebla',
            'city' => 'Cuautla',
        ])])]);

        $this->expectException(RuntimeException::class);
        app(PublicLocationGeocoder::class)->geocode('Morelos', 'Cuautla', 'Año de Juárez', '62748');
    }

    private function place(array $address = []): array
    {
        return [
            'lat' => $address['lat'] ?? '18.8123',
            'lon' => $address['lon'] ?? '-98.9556',
            'display_name' => $address['display_name'] ?? 'Año de Juárez, Cuautla, Morelos, México',
            'address' => array_merge([
                'country_code' => 'mx',
                'country' => 'México',
                'state' => 'Morelos',
                'city' => 'Cuautla',
                'neighbourhood' => 'Año de Juárez',
                'postcode' => '62748',
            ], $address),
        ];
    }
}
