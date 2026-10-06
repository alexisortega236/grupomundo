<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Enums\OperationType;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\ContactRequest;
use Illuminate\Http\Request;
use App\Support\PropertyTypeCatalog;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $query = Property::published()->with(['images', 'coverImage']);

        foreach (['property_type', 'state', 'neighborhood'] as $filter) {
            $query->when($request->filled($filter), function ($q) use ($filter, $request) {
                $value = $request->string($filter)->toString();

                if ($filter === 'property_type' && $value === 'commercial') {
                    $q->whereIn($filter, ['Oficina', 'Local', 'Consultorio', 'Bodega', 'commercial', 'office', 'local']);
                    return;
                }

                if ($filter === 'neighborhood') {
                    $q->where('neighborhood', $value);
                } else {
                    $q->where($filter, $value);
                }
            });
        }

        $query->when($request->filled('municipality'), function ($q) use ($request) {
            $value = $request->string('municipality')->toString();
            $q->where(fn ($nested) => $nested->where('municipality', $value)->orWhere(function ($legacy) use ($value) {
                $legacy->whereNull('municipality')->where('city', $value);
            })->orWhere('city', $value));
        })->when($request->filled('city') && ! $request->filled('municipality'), fn ($q) => $q->where('city', $request->string('city')->toString()));
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
                $q->where(fn ($nested) => $nested->where('title', 'like', $term)->orWhere('description', 'like', $term));
            });

        match ($request->input('sort', 'recent')) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'featured' => $query->orderByDesc('is_featured')->latest('published_at'),
            default => $query->latest('published_at'),
        };

        return view('public.properties.index', [
            'properties' => $query->paginate(9)->withQueryString(),
            'filters' => $request->query(),
            'options' => [
                'types' => PropertyTypeCatalog::options(),
                'states' => Property::published()->distinct()->pluck('state')->filter(),
                'municipalities' => Property::published()->selectRaw('COALESCE(municipality, city) as location_name')->distinct()->orderBy('location_name')->pluck('location_name')->filter(),
            ],
        ]);
    }

    public function show(Property $property)
    {
        abort_unless(
            $property->origin === Property::ORIGIN_COMMERCIAL
                && $property->status === PropertyStatus::Published
                && $property->published_at,
            404
        );

        return view('public.properties.show', [
            'property' => $property->load(['images', 'coverImage', 'videos', 'amenities']),
            'contactFormToken' => ContactRequest::issueFormToken(),
            'related' => Property::published()->with(['images', 'coverImage'])
                ->whereKeyNot($property->id)
                ->where('property_type', $property->property_type)
                ->take(3)->get(),
        ]);
    }
}
