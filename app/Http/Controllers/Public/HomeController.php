<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Support\PublicPropertyCatalog;
use App\Support\PropertyTypeCatalog;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        return view('public.home', [
            'featuredProperties' => Property::published()->featured()->with(['images', 'coverImage'])->latest('published_at')->take(10)->get(),
            'normalProperties' => PublicPropertyCatalog::query($request, true)->paginate(9)->withQueryString(),
            'options' => PublicPropertyCatalog::options($request),
        ]);
    }
}
