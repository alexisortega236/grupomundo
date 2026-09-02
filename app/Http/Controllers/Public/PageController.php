<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\ContactRequest;
use Illuminate\Support\Facades\Response;

class PageController extends Controller
{
    public function services()
    {
        return view('public.services');
    }

    public function about()
    {
        return view('public.about');
    }

    public function contact()
    {
        return view('public.contact', ['contactFormToken' => ContactRequest::issueFormToken()]);
    }

    public function sitemap()
    {
        $urls = collect([
            route('home'), route('properties.index'), route('services'), route('about'), route('contact'),
        ])->merge(Property::published()->pluck('slug')->map(fn ($slug) => route('properties.show', $slug)));

        return Response::view('public.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml');
    }
}
