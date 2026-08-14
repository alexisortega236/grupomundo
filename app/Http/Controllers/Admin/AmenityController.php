<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAmenityRequest;
use App\Models\Amenity;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AmenityController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('admin.amenities.index', ['amenities' => Amenity::orderBy('name')->paginate(20)]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.amenities.form', ['amenity' => new Amenity()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAmenityRequest $request)
    {
        Amenity::create($this->payload($request));
        return redirect()->route('admin.amenities.index')->with('status', 'Amenidad creada.');
    }

    /**
     * Display the specified resource.
     */
    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Amenity $amenity)
    {
        return view('admin.amenities.form', compact('amenity'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreAmenityRequest $request, Amenity $amenity)
    {
        $amenity->update($this->payload($request));
        return redirect()->route('admin.amenities.index')->with('status', 'Amenidad actualizada.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Amenity $amenity)
    {
        $amenity->delete();
        return back()->with('status', 'Amenidad eliminada.');
    }

    private function payload(StoreAmenityRequest $request): array
    {
        $data = $request->validated();
        $data['slug'] = $this->uniqueSlug($data['name'], $request->route('amenity')?->id);
        return $data;
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'amenidad';
        $slug = $base;
        $counter = 2;

        while (Amenity::where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
