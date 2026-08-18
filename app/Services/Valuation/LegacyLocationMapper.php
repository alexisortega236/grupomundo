<?php

namespace App\Services\Valuation;

class LegacyLocationMapper
{
    public function __construct(private readonly LegacyAvmCatalog $catalog)
    {
    }

    public function map(array $location): ?string
    {
        $explicit = $location['legacy_colonia'] ?? null;

        if ($explicit && in_array($explicit, $this->catalog->values(), true)) {
            return $explicit;
        }

        // The recovered legacy catalog only contains synthetic COL_XX buckets,
        // zone labels and factors. It has no real neighborhood, municipality or
        // coordinate metadata, so a real-world location cannot be mapped safely.
        return null;
    }
}
