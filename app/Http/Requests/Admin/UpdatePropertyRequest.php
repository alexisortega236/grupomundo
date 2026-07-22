<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class UpdatePropertyRequest extends StorePropertyRequest
{
    public function rules(): array
    {
        $property = $this->route('property');
        $rules = $this->baseRules();
        $rules['slug'] = ['nullable', 'string', 'max:255', Rule::unique('properties', 'slug')->ignore($property?->id)];
        $rules['delete_images'] = ['nullable', 'array'];
        $rules['delete_images.*'] = ['integer'];
        $rules['cover_image_id'] = ['nullable', 'integer'];
        $rules['image_alt'] = ['nullable', 'array'];
        $rules['image_position'] = ['nullable', 'array'];

        return $rules;
    }
}
