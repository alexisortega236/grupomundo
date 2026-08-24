<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Enums\AvmPropertyType;
use App\Models\Property;

class HomeController extends Controller
{
    public function __invoke()
    {
        return view('public.home', [
            'featuredProperties' => Property::published()->featured()->with(['images', 'coverImage'])->latest('published_at')->take(6)->get(),
            'propertyTypes' => Property::published()->distinct()->pluck('property_type')->filter()
                ->mapWithKeys(fn ($type) => [$type => AvmPropertyType::labelFor($type)]),
        ]);
    }
}
