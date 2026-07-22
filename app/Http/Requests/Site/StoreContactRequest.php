<?php

namespace App\Http\Requests\Site;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'website' => ['nullable', 'prohibited'],
        ];
    }
}
