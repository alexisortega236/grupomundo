<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAmenityRequest;
use App\Models\Amenity;
use Illuminate\Support\Str;

class AmenityController extends Controller
{
    public function index() { return view('admin.amenities.index', ['amenities' => Amenity::orderBy('name')->paginate(20)]); }
    public function create() { return view('admin.amenities.form', ['amenity' => new Amenity]); }
    public function edit(Amenity $amenity) { return view('admin.amenities.form', compact('amenity')); }

    public function store(StoreAmenityRequest $request)
    {
        Amenity::create(['name' => $request->name, 'slug' => Str::slug($request->name)]);
        return redirect()->route('admin.amenities.index')->with('status', 'Amenidad creada.');
    }

    public function update(StoreAmenityRequest $request, Amenity $amenity)
    {
        $amenity->update(['name' => $request->name, 'slug' => Str::slug($request->name)]);
        return redirect()->route('admin.amenities.index')->with('status', 'Amenidad actualizada.');
    }

    public function destroy(Amenity $amenity)
    {
        $amenity->delete();
        return back()->with('status', 'Amenidad eliminada.');
    }
}
