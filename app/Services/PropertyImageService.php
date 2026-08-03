<?php

namespace App\Services;

use App\Models\Property;
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

        foreach ($data['existing_images'] ?? [] as $imageId => $payload) {
            $property->images()->whereKey($imageId)->update([
                'alt_text' => $payload['alt_text'] ?? null,
                'position' => $payload['position'] ?? 0,
                'is_cover' => false,
            ]);
        }

        foreach ($data['images'] ?? [] as $index => $image) {
            $path = $image->store("properties/{$property->id}", 'public');
            $property->images()->create([
                'path' => $path,
                'alt_text' => $data['new_image_alt'][$index] ?? $property->title,
                'position' => 100 + $index,
                'is_cover' => false,
            ]);
        }

        $coverId = $data['cover_image_id'] ?? null;
        if ($coverId) {
            $property->images()->update(['is_cover' => false]);
            $property->images()->whereKey($coverId)->update(['is_cover' => true]);
        }

        if (! $property->images()->where('is_cover', true)->exists()) {
            $first = $property->images()->orderBy('position')->first();
            $first?->update(['is_cover' => true]);
        }
    }
}
