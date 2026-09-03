<?php

namespace Database\Seeders;

use App\Models\PostalSettlement;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Seeder;
use RuntimeException;

class CdmxOfficialColoniesSeeder extends Seeder
{
    private const SOURCE_URL = 'https://datos.cdmx.gob.mx/dataset/catalogo-de-colonias-datos-abiertos/resource/026b42d3-a609-44c7-a83d-22b2150caffc/download/026b42d3-a609-44c7-a83d-22b2150caffc.json';

    public function run(): void
    {
        $response = Http::acceptJson()->timeout(60)->get(self::SOURCE_URL);
        if ($response->failed()) {
            throw new RuntimeException('No se pudo descargar el catálogo oficial de colonias de CDMX.');
        }

        $features = $response->json('features');
        if (! is_array($features)) {
            throw new RuntimeException('El catálogo oficial de CDMX no contiene features válidos.');
        }

        $imported = 0;
        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? [];
            $settlement = trim((string) ($properties['colonia'] ?? ''));
            $municipality = trim((string) ($properties['alc'] ?? ''));

            if ($settlement === '' || $municipality === '') {
                continue;
            }

            $identity = [
                'source' => 'cdmx_oficial',
                'state_code' => $properties['cve_ent'] ?? '09',
                'municipality_code' => $properties['cve_alc'] ?? null,
                'settlement' => $settlement,
                'postal_code' => null,
            ];

            PostalSettlement::query()->firstOrNew($identity)->fill([
                ...$identity,
                'state' => 'Ciudad de México',
                'municipality' => $municipality,
                'settlement_type' => $properties['clasif'] ?? 'Colonia',
                'city' => 'Ciudad de México',
                'zone' => null,
            ])->save();
            $imported++;
        }

        $this->command?->info('CDMX official colonies imported: '.$imported);
    }
}
