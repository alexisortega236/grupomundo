<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use App\Models\Amenity;

class StoreAmenityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && in_array($this->user()->role, ['admin', 'editor'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->route('amenity')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                function (string $attribute, mixed $value, \Closure $fail) use ($id): void {
                    $candidate = Str::lower(Str::ascii(trim((string) $value)));
                    $equivalentExists = Amenity::query()
                        ->when($id, fn ($query) => $query->whereKeyNot($id))
                        ->get(['id', 'name'])
                        ->contains(fn (Amenity $amenity): bool => Str::lower(Str::ascii(trim($amenity->name))) === $candidate);

                    if ($equivalentExists) {
                        $fail('Ya existe una amenidad equivalente; los acentos y mayúsculas no distinguen nombres.');
                    }
                },
            ],
        ];
    }
}
