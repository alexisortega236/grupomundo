<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Enums\PropertyStatus;
use App\Models\ContactRequest;
use App\Models\Property;
use App\Support\PublicPropertyCatalog;
use Illuminate\Http\Request;
use App\Support\PropertyTypeCatalog;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        return view('public.properties.index', [
            'properties' => PublicPropertyCatalog::query($request)->paginate(9)->withQueryString(),
            'filters' => $request->query(),
            'options' => PublicPropertyCatalog::options($request),
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
