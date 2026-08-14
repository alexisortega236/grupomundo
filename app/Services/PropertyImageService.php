<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class PropertyImageService
{
    private const LARGE_WIDTH = 2200;
    private const CARD_WIDTH = 900;
    private const THUMB_WIDTH = 300;
    private const WEBP_QUALITY = 90;

    public function sync(Property $property, array $data): void
    {
        foreach ($data['delete_images'] ?? [] as $imageId) {
            $image = $property->images()->find($imageId);
            if ($image) {
                Storage::disk('public')->delete($image->paths());
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
            $versions = $this->storeOptimizedVersions($property, $image);

            $property->images()->create([
                'path' => $versions['path'],
                'card_path' => $versions['card_path'],
                'thumb_path' => $versions['thumb_path'],
                'alt_text' => $data['new_image_alt'][$index] ?? $property->title,
                'original_filename' => $image->getClientOriginalName(),
                'size_kb' => $versions['size_kb'],
                'width' => $versions['width'],
                'height' => $versions['height'],
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

    private function storeOptimizedVersions(Property $property, UploadedFile $file): array
    {
        $manager = new ImageManager(new Driver());
        $source = $manager->decodePath($file->getRealPath());
        $directory = "properties/{$property->id}";
        $name = Str::uuid()->toString();

        $large = $source->scaleDown(width: self::LARGE_WIDTH);
        $card = $source->scaleDown(width: self::CARD_WIDTH);
        $thumb = $source->scaleDown(width: self::THUMB_WIDTH);

        $paths = [
            'path' => "{$directory}/{$name}-large.webp",
            'card_path' => "{$directory}/{$name}-card.webp",
            'thumb_path' => "{$directory}/{$name}-thumb.webp",
        ];

        Storage::disk('public')->put($paths['path'], (string) $large->encodeUsingFileExtension('webp', quality: self::WEBP_QUALITY, strip: true));
        Storage::disk('public')->put($paths['card_path'], (string) $card->encodeUsingFileExtension('webp', quality: self::WEBP_QUALITY, strip: true));
        Storage::disk('public')->put($paths['thumb_path'], (string) $thumb->encodeUsingFileExtension('webp', quality: self::WEBP_QUALITY, strip: true));

        return [
            ...$paths,
            'size_kb' => (int) ceil(array_sum(array_map(
                fn (string $path) => Storage::disk('public')->size($path),
                $paths
            )) / 1024),
            'width' => $large->width(),
            'height' => $large->height(),
        ];
    }
}
