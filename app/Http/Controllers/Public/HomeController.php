<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Support\PropertyTypeCatalog;

class HomeController extends Controller
{
    public function __invoke()
    {
        return view('public.home', [
            'featuredProperties' => Property::published()->featured()->with(['images', 'coverImage'])->latest('published_at')->take(6)->get(),
            'normalProperties' => Property::published()->where('is_featured', false)->with(['images', 'coverImage'])->latest('published_at')->take(6)->get(),
            'propertyTypes' => PropertyTypeCatalog::options(),
        ]);
    }
}
