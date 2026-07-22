<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $query = Property::published()->with('images');
        $this->applyFilters($query, $request);

        match ($request->string('sort')->toString()) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'featured' => $query->orderByDesc('is_featured')->latest('published_at'),
            default => $query->latest('published_at'),
        };

        return view('public.properties.index', [
            'properties' => $query->paginate(9)->withQueryString(),
            'types' => Property::published()->distinct()->pluck('property_type')->filter()->values(),
            'states' => Property::published()->distinct()->pluck('state')->filter()->values(),
            'cities' => Property::published()->distinct()->pluck('city')->filter()->values(),
        ]);
    }

    public function show(Property $property)
    {
        abort_unless($property->status->value === 'published' && $property->published_at, 404);

        return view('public.properties.show', [
            'property' => $property->load(['images', 'amenities']),
            'related' => Property::published()->with('images')->whereKeyNot($property->id)->where('city', $property->city)->take(3)->get(),
        ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        foreach (['operation_type', 'property_type', 'state', 'city', 'neighborhood'] as $field) {
            $query->when($request->filled($field), fn ($q) => $q->where($field, $request->input($field)));
        }

        $query
            ->when($request->filled('min_price'), fn ($q) => $q->where('price', '>=', $request->input('min_price')))
            ->when($request->filled('max_price'), fn ($q) => $q->where('price', '<=', $request->input('max_price')))
            ->when($request->filled('bedrooms'), fn ($q) => $q->where('bedrooms', '>=', $request->input('bedrooms')))
            ->when($request->filled('bathrooms'), fn ($q) => $q->where('bathrooms', '>=', $request->input('bathrooms')))
            ->when($request->filled('keyword'), fn ($q) => $q->where(fn ($qq) => $qq->where('title', 'like', '%'.$request->keyword.'%')->orWhere('description', 'like', '%'.$request->keyword.'%')));
    }
}
