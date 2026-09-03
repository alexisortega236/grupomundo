<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePropertyRequest extends FormRequest
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $property = $this->route('property');
            $deletedIds = collect($this->input('delete_images', []))->map(fn ($id) => (int) $id);
            $remainingCount = $property
                ? $property->images()->whereNotIn('id', $deletedIds)->count()
                : 0;
            $newCount = count($this->file('images', []));

            if ($remainingCount + $newCount > 25) {
                $validator->errors()->add('images', 'Una propiedad puede tener como máximo 25 imágenes.');
            }

            $deletedVideos = collect($this->input('delete_videos', []))->map(fn ($id) => (int) $id);
            $remainingVideos = $property
                ? $property->videos()->whereNotIn('id', $deletedVideos)->count()
                : 0;
            $newVideos = count($this->file('videos', []));

            if ($remainingVideos + $newVideos > 3) {
                $validator->errors()->add('videos', 'Una propiedad puede tener como máximo 3 videos.');
            }
        });
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('property')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return StorePropertyRequest::rulesFor($this->route('property')?->id);
    }
}
