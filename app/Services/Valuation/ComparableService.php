<?php

namespace App\Services\Valuation;

use App\Models\Property;
use Illuminate\Support\Collection;

class ComparableService
{
    public function findForProperty(Property $property): Collection
    {
        return collect();
    }
}
