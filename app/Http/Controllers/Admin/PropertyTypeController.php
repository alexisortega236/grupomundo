<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePropertyTypeRequest;
use App\Models\Property;
use App\Models\PropertyType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PropertyTypeController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', PropertyType::class);

        return view('admin.property-types.index', [
            'propertyTypes' => PropertyType::withCount('properties')
                ->orderBy('sort_order')->orderBy('name')->paginate(20),
        ]);
    }

    public function create()
    {
        $this->authorize('create', PropertyType::class);

        return view('admin.property-types.form', ['propertyType' => new PropertyType(['is_active' => true])]);
    }

    public function store(StorePropertyTypeRequest $request)
    {
        $this->authorize('create', PropertyType::class);
        PropertyType::create($this->payload($request));

        return redirect()->route('admin.property-types.index')->with('status', 'Tipo de propiedad creado.');
    }

    public function edit(PropertyType $propertyType)
    {
        $this->authorize('update', $propertyType);

        return view('admin.property-types.form', compact('propertyType'));
    }

    public function update(StorePropertyTypeRequest $request, PropertyType $propertyType)
    {
        $this->authorize('update', $propertyType);
        $data = $this->payload($request);

        DB::transaction(function () use ($propertyType, $data): void {
            $oldName = $propertyType->name;
            $propertyType->update($data);

            if ($oldName !== $propertyType->name) {
                Property::where('property_type_id', $propertyType->id)->update(['property_type' => $propertyType->name]);
            }
        });

        return redirect()->route('admin.property-types.index')->with('status', 'Tipo de propiedad actualizado.');
    }

    public function toggleActive(PropertyType $propertyType)
    {
        $this->authorize('update', $propertyType);
        $propertyType->update(['is_active' => ! $propertyType->is_active]);

        return back()->with('status', $propertyType->is_active ? 'Tipo activado.' : 'Tipo desactivado.');
    }

    private function payload(StorePropertyTypeRequest $request): array
    {
        $data = $request->validated();
        $data['slug'] = $this->uniqueSlug($data['name'], $request->route('propertyType')?->id);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'tipo-propiedad';
        $slug = $base;
        $counter = 2;

        while (PropertyType::where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
