<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Property::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::rulesFor();
    }

    public static function rulesFor(?int $propertyId = null): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:properties,slug'.($propertyId ? ','.$propertyId : '')],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['required', 'string'],
            'operation_type' => ['required', 'in:sale,rent'],
            'property_type' => ['required', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'rent_period' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'exterior_number' => ['nullable', 'string', 'max:50'],
            'interior_number' => ['nullable', 'string', 'max:50'],
            'neighborhood' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'bedrooms' => ['nullable', 'integer', 'min:0'],
            'bathrooms' => ['nullable', 'numeric', 'min:0'],
            'parking_spaces' => ['nullable', 'integer', 'min:0'],
            'construction_area' => ['nullable', 'numeric', 'min:0'],
            'land_area' => ['nullable', 'numeric', 'min:0'],
            'age' => ['nullable', 'string', 'max:80'],
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
            'existing_images' => ['nullable', 'array'],
            'delete_images' => ['nullable', 'array'],
            'cover_image_id' => ['nullable', 'integer'],
        ];
    }
}
