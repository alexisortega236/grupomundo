<?php

namespace App\Services\Valuation;

use Illuminate\Support\Collection;

class LegacyAvmCatalog
{
    public function all(): Collection
    {
        $path = base_path('services/avm/app/data/catalogo_colonias.csv');

        if (! is_file($path)) {
            return collect();
        }

        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle) ?: [];
        $rows = collect();

        while (($row = fgetcsv($handle)) !== false) {
            $item = array_combine($headers, $row);
            if ($item && isset($item['colonia'])) {
                $rows->push($item);
            }
        }

        fclose($handle);

        return $rows;
    }

    public function options(): array
    {
        return $this->all()
            ->mapWithKeys(fn (array $row) => [$row['colonia'] => $this->label($row['colonia'], $row['zona'] ?? null)])
            ->all();
    }

    public function publicOptions(): array
    {
        return $this->all()
            ->mapWithKeys(fn (array $row) => [$this->publicKey($row['colonia']) => $this->label($row['colonia'], $row['zona'] ?? null)])
            ->all();
    }

    public function find(string $colonia): ?array
    {
        return $this->all()->firstWhere('colonia', $colonia);
    }

    public function values(): array
    {
        return $this->all()->pluck('colonia')->all();
    }

    public function publicValues(): array
    {
        return $this->all()
            ->pluck('colonia')
            ->map(fn (string $colonia) => $this->publicKey($colonia))
            ->all();
    }

    public function fromPublicKey(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        $number = str_pad($key, 2, '0', STR_PAD_LEFT);
        $colonia = 'COL_'.$number;

        return $this->find($colonia) ? $colonia : null;
    }

    public function label(string $colonia, ?string $zona = null): string
    {
        $number = ltrim(str_replace('COL_', '', $colonia), '0') ?: $colonia;
        $label = 'Colonia '.$number;

        return $zona ? $label.' - zona '.$zona : $label;
    }

    private function publicKey(string $colonia): string
    {
        return (string) ((int) str_replace('COL_', '', $colonia));
    }
}
