<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PropertyImageService
{
    public function sync(Property $property, array $data): void
    {
        foreach ($data['delete_images'] ?? [] as $imageId) {
            $image = $property->images()->find($imageId);
            if ($image) {
                Storage::disk('public')->delete($image->path);
                $image->delete();
            }
        }

        foreach ($property->images as $image) {
            $image->update([
                'alt_text' => $data['image_alt'][$image->id] ?? $image->alt_text,
                'position' => (int) ($data['image_position'][$image->id] ?? $image->position),
                'is_cover' => (string) ($data['cover_image_id'] ?? '') === (string) $image->id,
            ]);
        }

        foreach ($data['images'] ?? [] as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store("properties/{$property->id}", 'public');
            $property->images()->create([
                'path' => $path,
                'alt_text' => $data['new_image_alt'][$index] ?? $property->title,
                'position' => (int) $property->images()->max('position') + 1,
                'is_cover' => false,
            ]);
        }

        $property->load('images');
        if (! $property->images->contains('is_cover', true) && $property->images->isNotEmpty()) {
            $property->images()->orderBy('position')->first()?->update(['is_cover' => true]);
        }
    }

    public function deleteAll(Property $property): void
    {
        foreach ($property->images as $image) {
            Storage::disk('public')->delete($image->path);
        }
    }
}
