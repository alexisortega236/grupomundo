<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Enums\PropertyStatus;
use App\Models\Property;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $query = Property::published()->with('images');

        foreach (['operation_type', 'property_type', 'state', 'city', 'neighborhood'] as $filter) {
            $query->when($request->filled($filter), fn ($q) => $q->where($filter, $request->string($filter)));
        }

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
                'types' => Property::published()->distinct()->pluck('property_type')->filter(),
                'states' => Property::published()->distinct()->pluck('state')->filter(),
                'cities' => Property::published()->distinct()->pluck('city')->filter(),
            ],
        ]);
    }

    public function show(Property $property)
    {
        abort_unless($property->status === PropertyStatus::Published && $property->published_at, 404);

        return view('public.properties.show', [
            'property' => $property->load(['images', 'amenities']),
            'related' => Property::published()->with('images')
                ->whereKeyNot($property->id)
                ->where('property_type', $property->property_type)
                ->take(3)->get(),
        ]);
    }
}
