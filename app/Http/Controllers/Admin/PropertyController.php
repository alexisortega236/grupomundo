<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PropertyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePropertyRequest;
use App\Http\Requests\Admin\UpdatePropertyRequest;
use App\Models\Amenity;
use App\Models\Property;
use App\Services\PropertyImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PropertyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $properties = Property::withTrashed()
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('operation_type'), fn ($q) => $q->where('operation_type', $request->string('operation_type')))
            ->when($request->filled('property_type'), fn ($q) => $q->where('property_type', $request->string('property_type')))
            ->latest()->paginate(15)->withQueryString();

        return view('admin.properties.index', compact('properties'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.properties.form', [
            'property' => new Property(['currency' => 'MXN', 'status' => PropertyStatus::Published, 'published_at' => now()]),
            'amenities' => Amenity::orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePropertyRequest $request, PropertyImageService $images)
    {
        $property = DB::transaction(function () use ($request, $images) {
            $data = $this->propertyPayload($request);
            $data['created_by'] = $request->user()->id;
            $property = Property::create($data);
            $property->amenities()->sync($request->input('amenities', []));
            $images->sync($property, $request->validated());
            return $property;
        });

        return redirect()->route('admin.properties.show', $property)->with('status', 'Propiedad creada.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Property $property)
    {
        return view('admin.properties.show', ['property' => $property->load(['images', 'amenities', 'contactRequests'])]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Property $property)
    {
        return view('admin.properties.form', [
            'property' => $property->load(['images', 'amenities']),
            'amenities' => Amenity::orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePropertyRequest $request, Property $property, PropertyImageService $images)
    {
        DB::transaction(function () use ($request, $property, $images) {
            $property->update($this->propertyPayload($request));
            $property->amenities()->sync($request->input('amenities', []));
            $images->sync($property, $request->validated());
        });

        return redirect()->route('admin.properties.show', $property)->with('status', 'Propiedad actualizada.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Property $property)
    {
        $this->authorize('delete', $property);
        $property->delete();
        return redirect()->route('admin.properties.index')->with('status', 'Propiedad enviada a eliminadas.');
    }

    public function togglePublished(Property $property)
    {
        $this->authorize('update', $property);
        $property->update([
            'status' => $property->status === PropertyStatus::Published ? PropertyStatus::Draft : PropertyStatus::Published,
            'published_at' => $property->status === PropertyStatus::Published ? null : now(),
        ]);
        return back()->with('status', 'Publicacion actualizada.');
    }

    public function archive(Property $property)
    {
        $this->authorize('update', $property);
        $property->update(['status' => PropertyStatus::Archived]);
        return back()->with('status', 'Propiedad archivada.');
    }

    public function restore(int $property)
    {
        $property = Property::withTrashed()->findOrFail($property);
        $this->authorize('restore', $property);
        $property->restore();
        return back()->with('status', 'Propiedad restaurada.');
    }

    public function forceDelete(int $property)
    {
        $property = Property::withTrashed()->findOrFail($property);
        $this->authorize('forceDelete', $property);
        foreach ($property->images as $image) {
            Storage::disk('public')->delete($image->path);
        }
        $property->forceDelete();
        return redirect()->route('admin.properties.index')->with('status', 'Propiedad eliminada permanentemente.');
    }

    private function propertyPayload(Request $request): array
    {
        $data = collect($request->validated())->except([
            'amenities', 'images', 'new_image_alt', 'existing_images', 'delete_images', 'cover_image_id',
        ])->all();
        $data['slug'] = $data['slug'] ?: Str::slug($data['title']);
        $data['is_featured'] = $request->boolean('is_featured');
        $data['published_at'] = $data['status'] === PropertyStatus::Published->value
            ? ($data['published_at'] ?? now())
            : null;

        return $data;
    }
}
