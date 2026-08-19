<?php

namespace Tests\Feature;

use App\Models\PostalSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SepomexLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sepomex_import_is_morelos_only_and_idempotent(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sepomex-');
        file_put_contents($file, implode("\n", [
            'd_codigo,d_asenta,d_tipo_asenta,D_mnpio,d_estado,d_ciudad,c_estado,c_mnpio,c_zona',
            '62740,Año de Juárez,Colonia,Cuautla,Morelos,Cuautla,17,006,Urbano',
            '62000,Centro,Colonia,Cuernavaca,Morelos,Cuernavaca,17,007,Urbano',
            '01000,Centro,Colonia,Álvaro Obregón,Ciudad de México,,,' ,
        ]));

        $this->artisan('locations:import-sepomex', ['file' => $file])->assertExitCode(0);
        $this->artisan('locations:import-sepomex', ['file' => $file])->assertExitCode(0);

        $this->assertSame(2, PostalSettlement::count());
        $this->assertSame('Año de Juárez', PostalSettlement::firstWhere('postal_code', '62740')->settlement);
        $this->assertSame(2, PostalSettlement::where('state', 'Morelos')->count());
        @unlink($file);
    }

    public function test_sepomex_pipe_txt_preserves_official_columns_and_accents(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sepomex-').'.txt';
        $contents = implode("\n", [
            'd_codigo|d_asenta|d_tipo_asenta|D_mnpio|d_estado|d_ciudad|c_estado|c_mnpio|d_zona|id_asenta_cpcons',
            '62740|Año de Juárez|Colonia|Cuautla|Morelos|Cuautla|17|006|Urbano|170060001',
            '62741|Centro|Colonia|Cuautla|Morelos|Cuautla|17|006|Urbano|170060002',
        ]);
        file_put_contents($file, iconv('UTF-8', 'Windows-1252', $contents));

        $this->artisan('locations:import-sepomex', ['file' => $file])->assertExitCode(0);

        $settlement = PostalSettlement::where('postal_code', '62740')->first();
        $this->assertNotNull($settlement);
        $this->assertSame('Año de Juárez', $settlement->settlement);
        $this->assertSame('Colonia', $settlement->settlement_type);
        $this->assertSame('17', $settlement->state_code);
        $this->assertSame('006', $settlement->municipality_code);
        $this->assertSame('Urbano', $settlement->zone);
        $this->assertSame(2, PostalSettlement::count());

        @unlink($file);
    }

    public function test_sepomex_ignores_legal_preamble_and_reads_iso88591_header_on_second_line(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sepomex-').'.txt';
        $contents = implode("\n", [
            'Este archivo contiene información oficial de códigos postales.',
            'd_codigo|d_asenta|d_tipo_asenta|D_mnpio|d_estado|d_ciudad|d_CP|c_estado|c_oficina|c_tipo_asenta|c_mnpio|id_asenta_cpcons|d_zona|c_cve_ciudad|c_CP',
            '62100|Cuernavaca Centro|Colonia|Cuernavaca|Morelos|Cuernavaca|62100|17|0001|09|007|170070001|Urbano|001|62100',
            '62740|Año de Juárez|Colonia|Cuautla|Morelos|Cuautla|62740|17|0006|09|006|170060001|Urbano|002|62740',
        ]);
        file_put_contents($file, iconv('UTF-8', 'ISO-8859-1', $contents));

        $this->artisan('locations:import-sepomex', ['file' => $file])->assertExitCode(0);

        $this->assertSame('Cuernavaca Centro', PostalSettlement::where('postal_code', '62100')->value('settlement'));
        $this->assertSame('Año de Juárez', PostalSettlement::where('postal_code', '62740')->value('settlement'));
        $this->assertSame(2, PostalSettlement::count());

        @unlink($file);
    }

    public function test_municipalities_and_settlements_are_filtered(): void
    {
        PostalSettlement::create([
            'state' => 'Morelos',
            'state_code' => '17',
            'municipality' => 'Cuautla',
            'municipality_code' => '006',
            'settlement' => 'Año de Juárez',
            'settlement_type' => 'Colonia',
            'postal_code' => '62740',
            'source' => 'sepomex',
        ]);
        PostalSettlement::create([
            'state' => 'Morelos',
            'municipality' => 'Cuautla',
            'settlement' => 'Año Nuevo',
            'postal_code' => '62741',
            'source' => 'sepomex',
        ]);

        $this->getJson('/valuador/locations/municipalities?state=Morelos')
            ->assertOk()
            ->assertJsonFragment(['Cuautla']);
        $this->getJson('/valuador/locations/settlements?state=Morelos&municipality=Cuautla&q=Año')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['name' => 'Año de Juárez', 'type' => 'Colonia', 'postal_code' => '62740']);
    }

    public function test_manual_settlement_is_geocoded_on_server_before_valuation(): void
    {
        $settlement = PostalSettlement::create([
            'state' => 'Morelos',
            'state_code' => '17',
            'municipality' => 'Cuautla',
            'municipality_code' => '006',
            'settlement' => 'Año de Juárez',
            'settlement_type' => 'Colonia',
            'postal_code' => '62740',
            'source' => 'sepomex',
        ]);
        config(['services.nominatim.url' => 'https://nominatim.test', 'services.avm_v2.enabled' => false, 'services.avm_v2_v1.enabled' => false]);
        Http::fake([
            'https://nominatim.test/search*' => Http::response([[
                'lat' => '18.8123',
                'lon' => '-98.9556',
                'display_name' => 'Año de Juárez, Cuautla, Morelos, México',
                'address' => [
                    'state' => 'Morelos',
                    'county' => 'Municipio de Cuautla',
                    'city' => 'Cuautla',
                    'neighbourhood' => 'Año de Juárez',
                    'postcode' => '62740',
                ],
            ]]),
        ]);

        $response = $this->post(route('valuation.store'), [
            'property_type' => 'house',
            'state' => 'Morelos',
            'municipality' => 'Cuautla',
            'neighborhood' => 'Año de Juárez',
            'settlement_id' => $settlement->id,
            'land_area_m2' => 100,
            'construction_area_m2' => 80,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'parking_spaces' => 2,
            'property_age_years' => 5,
        ]);

        $response->assertRedirect();
        $property = \App\Models\Property::first();
        $this->assertSame('Año de Juárez', $property->neighborhood);
        $this->assertSame('62740', $property->postal_code);
        $this->assertSame('sepomex_geocoded', $property->location_source);
        $this->assertSame('neighborhood', $property->location_precision);
        $this->assertSame('18.8123000', $property->latitude);
        $this->assertSame('-98.9556000', $property->longitude);
    }
}
