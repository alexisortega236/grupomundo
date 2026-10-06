<?php

namespace App\Support;

use App\Enums\OperationType;
use App\Models\Property;
use Illuminate\Http\Request;

class PublicPropertyCatalog
{
    public static function query(Request $request, bool $excludeFeatured = false)
    {
        $query = Property::published()->with(['images', 'coverImage']);

        if ($excludeFeatured) {
            $query->where('is_featured', false);
        }

        foreach (['property_type', 'state'] as $filter) {
            $query->when($request->filled($filter), function ($q) use ($filter, $request) {
                $value = $request->string($filter)->toString();

                if ($filter === 'property_type' && $value === 'commercial') {
                    $q->whereIn($filter, ['Oficina', 'Local', 'Consultorio', 'Bodega', 'commercial', 'office', 'local']);
                    return;
                }

                $q->where($filter, $value);
            });
        }

        $query->when($request->filled('municipality'), function ($q) use ($request) {
            $value = $request->string('municipality')->toString();
            $q->where(fn ($nested) => $nested
                ->where('municipality', $value)
                ->orWhere(fn ($legacy) => $legacy->whereNull('municipality')->where('city', $value)));
        })->when($request->filled('city') && ! $request->filled('municipality'), fn ($q) => $q->where('city', $request->string('city')->toString()));

        $query->when($request->filled('neighborhood'), fn ($q) => $q->where('neighborhood', $request->string('neighborhood')->toString()));

        $operation = $request->string('operation_type')->toString();
        $query->when($operation !== '', function ($q) use ($operation) {
            $values = match ($operation) {
                OperationType::Sale->value => [OperationType::Sale->value, OperationType::SaleRent->value],
                OperationType::Rent->value => [OperationType::Rent->value, OperationType::SaleRent->value],
                OperationType::Presale->value => [OperationType::Presale->value],
                default => [$operation],
            };

            $q->whereIn('operation_type', $values);
        });

        $query
            ->when($request->filled('min_price'), fn ($q) => $q->where('price', '>=', $request->float('min_price')))
            ->when($request->filled('max_price'), fn ($q) => $q->where('price', '<=', $request->float('max_price')))
            ->when($request->filled('bedrooms'), fn ($q) => $q->where('bedrooms', '>=', $request->integer('bedrooms')))
            ->when($request->filled('bathrooms'), fn ($q) => $q->where('bathrooms', '>=', $request->float('bathrooms')))
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $term = '%'.$request->string('keyword')->toString().'%';
                $q->where(fn ($nested) => $nested
                    ->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('state', 'like', $term)
                    ->orWhere('municipality', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('neighborhood', 'like', $term));
            });

        return match ($request->input('sort', 'recent')) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'featured' => $query->orderByDesc('is_featured')->latest('published_at'),
            default => $query->latest('published_at'),
        };
    }

    public static function options(Request $request): array
    {
        $state = $request->string('state')->toString();
        $locations = Property::published()->when($state !== '', fn ($query) => $query->where('state', $state));

        return [
            'types' => PropertyTypeCatalog::options(),
            'states' => Property::published()->distinct()->orderBy('state')->pluck('state')->filter(),
            'municipalities' => $locations->selectRaw('COALESCE(municipality, city) as location_name')->distinct()->orderBy('location_name')->pluck('location_name')->filter(),
        ];
    }
}
