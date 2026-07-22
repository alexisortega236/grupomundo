<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Property;

class HomeController extends Controller
{
    public function __invoke()
    {
        return view('public.home', [
            'featuredProperties' => Property::published()->featured()->with('images')->latest('published_at')->take(6)->get(),
            'types' => Property::published()->distinct()->pluck('property_type')->filter()->values(),
        ]);
    }
}
