<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdmin() ?? false;
    }

    public function rules(): array
    {
        return $this->baseRules();
    }

    protected function baseRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:properties,slug'],
            'short_description' => ['nullable', 'string'],
            'description' => ['required', 'string'],
            'operation_type' => ['required', 'in:sale,rent'],
            'property_type' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'rent_period' => ['nullable', 'string', 'max:80'],
            'street' => ['nullable', 'string', 'max:255'],
            'exterior_number' => ['nullable', 'string', 'max:40'],
            'interior_number' => ['nullable', 'string', 'max:40'],
            'neighborhood' => ['required', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:160'],
            'state' => ['required', 'string', 'max:160'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:30'],
            'bathrooms' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'parking_spaces' => ['nullable', 'integer', 'min:0', 'max:30'],
            'construction_area' => ['nullable', 'numeric', 'min:0'],
            'land_area' => ['nullable', 'numeric', 'min:0'],
            'age' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['required', 'in:draft,published,sold,rented,archived'],
            'is_featured' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['integer', 'exists:amenities,id'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'new_image_alt' => ['nullable', 'array'],
        ];
    }
}
