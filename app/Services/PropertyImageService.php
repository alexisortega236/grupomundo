<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

class PropertyImageService
{
    private const LARGE_WIDTH = 2400;
    private const CARD_WIDTH = 1000;
    private const THUMB_WIDTH = 400;
    private const WEBP_QUALITY = 88;

    public function sync(Property $property, array $data): void
    {
        $createdPaths = [];

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

        $createdImageIds = [];
        try {
            foreach ($data['images'] ?? [] as $index => $image) {
                $versions = $this->storeOptimizedVersions($property, $image);
                $createdPaths = [...$createdPaths, ...$versions];

                $createdImage = $property->images()->create([
                    'path' => $versions['path'],
                    'original_path' => $versions['original_path'],
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
                $createdImageIds[$index] = $createdImage->id;
            }
        } catch (Throwable $exception) {
            Storage::disk('public')->delete(array_values(array_filter($createdPaths, 'is_string')));
            throw $exception;
        }

        $coverId = $data['cover_image_id'] ?? null;
        $newCoverIndex = $data['cover_image_new'] ?? null;
        $newCoverId = $newCoverIndex !== null ? ($createdImageIds[$newCoverIndex] ?? null) : null;
        if ($coverId || $newCoverId) {
            $property->images()->update(['is_cover' => false]);
            $selectedCoverId = $newCoverId ?: $coverId;
            $property->images()->whereKey($selectedCoverId)->update(['is_cover' => true]);
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

        $extension = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $originalPath = "{$directory}/{$name}-original.{$extension}";
        $paths = [
            'path' => "{$directory}/{$name}-large.webp",
            'card_path' => "{$directory}/{$name}-card.webp",
            'thumb_path' => "{$directory}/{$name}-thumb.webp",
            'original_path' => $originalPath,
        ];

        try {
            Storage::disk('public')->put($originalPath, $file->get());

            if (method_exists($source, 'orient')) {
                $source = $source->orient();
            }

            // scaleDown mutates the Image instance; clone the oriented source
            // so every derivative starts from the same original dimensions.
            $large = (clone $source)->scaleDown(width: self::LARGE_WIDTH);
            $card = (clone $source)->scaleDown(width: self::CARD_WIDTH);
            $thumb = (clone $source)->scaleDown(width: self::THUMB_WIDTH);

            Storage::disk('public')->put($paths['path'], (string) $large->encodeUsingFileExtension('webp', quality: self::WEBP_QUALITY, strip: true));
            Storage::disk('public')->put($paths['card_path'], (string) $card->encodeUsingFileExtension('webp', quality: self::WEBP_QUALITY, strip: true));
            Storage::disk('public')->put($paths['thumb_path'], (string) $thumb->encodeUsingFileExtension('webp', quality: self::WEBP_QUALITY, strip: true));
        } catch (Throwable $exception) {
            Storage::disk('public')->delete(array_values($paths));
            throw $exception;
        }

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
