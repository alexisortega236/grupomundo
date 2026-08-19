<?php

namespace App\Console\Commands;

use App\Models\PostalSettlement;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class ImportSepomexSettlements extends Command
{
    protected $signature = 'locations:import-sepomex {file : Ruta al CSV oficial de SEPOMEX}';

    protected $description = 'Importa asentamientos SEPOMEX de Morelos de forma idempotente.';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("No se puede leer el archivo: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("No se pudo abrir el archivo: {$path}");

            return self::FAILURE;
        }

        $header = null;
        $delimiter = null;
        while (($line = fgets($handle)) !== false) {
            $decodedLine = $this->decode($line);
            $candidateDelimiter = $this->detectDelimiter($decodedLine);
            $candidateHeader = str_getcsv($decodedLine, $candidateDelimiter);
            $candidateKeys = array_map(fn ($value) => $this->headerKey((string) $value), $candidateHeader);

            if (! array_diff(['state', 'municipality', 'settlement'], $candidateKeys)) {
                $header = $candidateHeader;
                $delimiter = $candidateDelimiter;
                break;
            }
        }

        if (! is_array($header) || $delimiter === null) {
            fclose($handle);
            $this->error('No se encontró un encabezado SEPOMEX válido en las primeras líneas del archivo.');

            return self::FAILURE;
        }

        $header = array_map(fn ($value) => $this->headerKey($this->decode((string) $value)), $header);
        $required = ['state', 'municipality', 'settlement'];
        if (array_diff($required, $header)) {
            fclose($handle);
            $this->error('El archivo no contiene las columnas SEPOMEX necesarias: '.implode(', ', array_diff($required, $header)));

            return self::FAILURE;
        }

        $read = $morelos = $created = $updated = $skipped = $errors = 0;
        while (($line = fgets($handle)) !== false) {
            $values = str_getcsv($this->decode($line), $delimiter);
            $read++;
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                $skipped++;
                continue;
            }

            $row = [];
            foreach ($header as $index => $key) {
                $row[$key] = $this->clean($values[$index] ?? null);
            }

            if ($this->normalize($row['state'] ?? '') !== $this->normalize('Morelos')) {
                $skipped++;
                continue;
            }
            $morelos++;

            if (blank($row['municipality'] ?? null) || blank($row['settlement'] ?? null)) {
                $errors++;
                continue;
            }

            $identity = [
                'source' => 'sepomex',
                'state_code' => $row['state_code'] ?? null,
                'municipality_code' => $row['municipality_code'] ?? null,
                'settlement' => $row['settlement'],
                'postal_code' => $row['postal_code'] ?? null,
            ];
            $payload = [
                ...$identity,
                'state' => $row['state'],
                'municipality' => $row['municipality'],
                'settlement_type' => $row['settlement_type'] ?? null,
                'city' => $row['city'] ?? null,
                'zone' => $row['zone'] ?? null,
            ];

            try {
                $settlement = PostalSettlement::query()->firstOrNew($identity);
                $wasExisting = $settlement->exists;
                $settlement->fill($payload)->save();
                $wasExisting ? $updated++ : $created++;
            } catch (Throwable $exception) {
                $errors++;
                $this->warn('No se pudo importar una fila: '.$exception->getMessage());
            }
        }
        fclose($handle);

        $this->table(['Métrica', 'Total'], [
            ['Registros leídos', $read],
            ['Registros Morelos', $morelos],
            ['Creados', $created],
            ['Actualizados', $updated],
            ['Omitidos', $skipped],
            ['Errores', $errors],
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function detectDelimiter(string $line): string
    {
        $candidates = [
            '|' => substr_count($line, '|'),
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
        ];

        return (string) array_key_first(array_filter($candidates, fn ($count) => $count === max($candidates)));
    }

    private function headerKey(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $key = $this->normalize($value);

        return match ($key) {
            'destado', 'estado', 'state' => 'state',
            'cestado', 'statecode', 'codigoestado' => 'state_code',
            'dmnpio', 'dmunpio', 'municipio', 'municipality' => 'municipality',
            'cmnpio', 'municipalitycode', 'codigomunicipio' => 'municipality_code',
            'dasenta', 'asentamiento', 'settlement' => 'settlement',
            'dtipoasenta', 'tipoasentamiento', 'settlementtype' => 'settlement_type',
            'dcodigo', 'codigopostal', 'cp', 'postalcode' => 'postal_code',
            'dciudad', 'ciudad', 'city' => 'city',
            'dzona', 'czona', 'zona', 'zone' => 'zone',
            default => $key,
        };
    }

    private function clean(mixed $value): ?string
    {
        $value = trim($this->decode((string) $value));

        return $value === '' ? null : preg_replace('/\s+/u', ' ', $value);
    }

    private function decode(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

        if ((function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8'))
            || (! function_exists('mb_check_encoding') && preg_match('//u', $value) === 1)) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'Windows-1252, ISO-8859-1');
        }

        return iconv('Windows-1252', 'UTF-8//IGNORE', $value) ?: $value;
    }

    private function normalize(string $value): string
    {
        return (string) Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->value();
    }
}
