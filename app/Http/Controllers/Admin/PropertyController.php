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
use Illuminate\Support\Str;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $query = Property::withTrashed()->with('images')
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->q.'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('operation_type'), fn ($q) => $q->where('operation_type', $request->operation_type))
            ->when($request->filled('property_type'), fn ($q) => $q->where('property_type', $request->property_type));

        return view('admin.properties.index', ['properties' => $query->latest()->paginate(15)->withQueryString()]);
    }

    public function create()
    {
        return view('admin.properties.form', ['property' => new Property(['currency' => 'MXN', 'status' => 'draft']), 'amenities' => Amenity::orderBy('name')->get()]);
    }

    public function store(StorePropertyRequest $request, PropertyImageService $images)
    {
        $property = DB::transaction(function () use ($request, $images) {
            $data = $this->payload($request->validated());
            $data['created_by'] = $request->user()->id;
            $property = Property::create($data);
            $property->amenities()->sync($request->input('amenities', []));
            $images->sync($property, $request->all());

            return $property;
        });

        return redirect()->route('admin.properties.edit', $property)->with('status', 'Propiedad creada.');
    }

    public function show(Property $property)
    {
        return view('admin.properties.show', ['property' => $property->load(['images', 'amenities', 'contactRequests'])]);
    }

    public function edit(Property $property)
    {
        return view('admin.properties.form', ['property' => $property->load(['images', 'amenities']), 'amenities' => Amenity::orderBy('name')->get()]);
    }

    public function update(UpdatePropertyRequest $request, Property $property, PropertyImageService $images)
    {
        DB::transaction(function () use ($request, $property, $images) {
            $property->update($this->payload($request->validated()));
            $property->amenities()->sync($request->input('amenities', []));
            $images->sync($property, $request->all());
        });

        return back()->with('status', 'Propiedad actualizada.');
    }

    public function destroy(Property $property)
    {
        $property->delete();

        return back()->with('status', 'Propiedad enviada a papelera.');
    }

    public function archive(Property $property)
    {
        $property->update(['status' => PropertyStatus::Archived]);

        return back()->with('status', 'Propiedad archivada.');
    }

    public function restore(int $property)
    {
        Property::withTrashed()->findOrFail($property)->restore();

        return back()->with('status', 'Propiedad restaurada.');
    }

    public function forceDelete(int $property, PropertyImageService $images)
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $property = Property::withTrashed()->with('images')->findOrFail($property);
        $images->deleteAll($property);
        $property->forceDelete();

        return back()->with('status', 'Propiedad eliminada permanentemente.');
    }

    public function togglePublished(Property $property)
    {
        $published = $property->status !== PropertyStatus::Published;
        $property->update(['status' => $published ? 'published' : 'draft', 'published_at' => $published ? now() : null]);

        return back()->with('status', $published ? 'Propiedad publicada.' : 'Propiedad despublicada.');
    }

    private function payload(array $data): array
    {
        $data['slug'] = $data['slug'] ?: Str::slug($data['title']);
        $data['is_featured'] = (bool) ($data['is_featured'] ?? false);
        if (($data['status'] ?? null) === 'published' && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        return collect($data)->except(['amenities', 'images', 'new_image_alt', 'delete_images', 'cover_image_id', 'image_alt', 'image_position'])->all();
    }
}
