<?php

namespace App\Http\Requests\Admin;

use App\Enums\OperationType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePropertyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency' => strtoupper((string) $this->input('currency', 'MXN')),
            'price' => is_string($this->input('price'))
                ? str_replace(',', '', $this->input('price'))
                : $this->input('price'),
        ]);
    }

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
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['required', 'string'],
            'operation_type' => ['required', Rule::enum(OperationType::class)],
            'property_type' => ['required', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', Rule::in(['MXN', 'USD'])],
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
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['integer', 'exists:amenities,id'],
            'images' => ['nullable', 'array', 'max:25'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp'],
            'new_image_alt' => ['nullable', 'array'],
            'existing_images' => ['nullable', 'array'],
            'delete_images' => ['nullable', 'array'],
            'cover_image_id' => ['nullable', 'integer'],
            'cover_image_new' => ['nullable', 'integer', 'min:0'],
            'videos' => ['nullable', 'array', 'max:3'],
            'videos.*' => ['file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-msvideo,video/x-matroska'],
            'delete_videos' => ['nullable', 'array'],
            'delete_videos.*' => ['integer'],
        ];
    }
}
