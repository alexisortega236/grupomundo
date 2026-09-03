<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PropertyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePropertyRequest;
use App\Http\Requests\Admin\UpdatePropertyRequest;
use App\Models\Amenity;
use App\Models\Property;
use App\Services\PropertyImageService;
use App\Services\PropertyVideoService;
use App\Services\Valuation\SupportedValuationLocations;
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
        $properties = Property::commercial()->withTrashed()
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
    public function create(SupportedValuationLocations $locations)
    {
        return view('admin.properties.form', [
            'property' => new Property([
                'currency' => 'MXN',
                'status' => PropertyStatus::Published,
                'origin' => Property::ORIGIN_COMMERCIAL,
            ]),
            'amenities' => Amenity::orderBy('name')->get(),
            'locationStates' => $locations->states(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePropertyRequest $request, PropertyImageService $images, PropertyVideoService $videos)
    {
        $property = DB::transaction(function () use ($request, $images, $videos) {
            $data = $this->propertyPayload($request);
            $data['slug'] = $this->uniqueSlug($data['title']);
            $data['created_by'] = $request->user()->id;
            $data['origin'] = Property::ORIGIN_COMMERCIAL;
            $property = Property::create($data);
            $property->amenities()->sync($request->input('amenities', []));
            $images->sync($property, $request->validated());
            $videos->sync($property, $request->validated());
            return $property;
        });

        return redirect()->route('admin.properties.show', $property)->with('status', 'Propiedad creada.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Property $property)
    {
        return view('admin.properties.show', ['property' => $property->load(['images', 'videos', 'amenities', 'contactRequests'])]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Property $property, SupportedValuationLocations $locations)
    {
        $this->authorize('update', $property);

        return view('admin.properties.form', [
            'property' => $property->load(['images', 'videos', 'amenities']),
            'amenities' => Amenity::orderBy('name')->get(),
            'locationStates' => $locations->states(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePropertyRequest $request, Property $property, PropertyImageService $images, PropertyVideoService $videos)
    {
        DB::transaction(function () use ($request, $property, $images, $videos) {
            $property->update($this->propertyPayload($request, $property));
            $property->amenities()->sync($request->input('amenities', []));
            $images->sync($property, $request->validated());
            $videos->sync($property, $request->validated());
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
        $this->authorize('publish', $property);
        $property->update([
            'status' => $property->status === PropertyStatus::Published ? PropertyStatus::Draft : PropertyStatus::Published,
            'published_at' => $property->published_at ?: now(),
        ]);
        return back()->with('status', 'Publicación actualizada.');
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
            Storage::disk('public')->delete($image->paths());
        }
        foreach ($property->videos as $video) {
            Storage::disk('public')->delete($video->path);
        }
        $property->forceDelete();
        return redirect()->route('admin.properties.index')->with('status', 'Propiedad eliminada permanentemente.');
    }

    private function propertyPayload(Request $request, ?Property $property = null): array
    {
        $data = collect($request->validated())->except([
            'slug', 'published_at', 'amenities', 'images', 'new_image_alt', 'existing_images', 'delete_images', 'cover_image_id', 'cover_image_new', 'videos', 'delete_videos',
        ])->all();
        $data['is_featured'] = $request->boolean('is_featured');
        if (($data['status'] ?? null) === PropertyStatus::Published->value && ! $property?->published_at) {
            $data['published_at'] = now();
        }

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'propiedad';
        $slug = $base;
        $counter = 2;

        while (Property::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
