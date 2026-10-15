<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PropertyVideoService
{
    public function sync(Property $property, array $data): void
    {
        foreach ($data['delete_videos'] ?? [] as $videoId) {
            $video = $property->videos()->find($videoId);
            if ($video) {
                Storage::disk('public')->delete($video->path);
                $video->delete();
            }
        }

        $createdPaths = [];
        try {
            $position = (int) $property->videos()->max('position');

            foreach ($data['videos'] ?? [] as $index => $video) {
                if (! $video instanceof UploadedFile) {
                    continue;
                }

                $path = $video->storeAs(
                    "properties/{$property->id}/videos",
                    Str::uuid()->toString().'.'.strtolower($video->getClientOriginalExtension() ?: 'mp4'),
                    'public'
                );
                $createdPaths[] = $path;

                $property->videos()->create([
                    'path' => $path,
                    'original_filename' => $video->getClientOriginalName(),
                    'mime_type' => $video->getMimeType(),
                    'size_bytes' => $video->getSize(),
                    'position' => ++$position,
                ]);
            }
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($createdPaths);
            throw $exception;
        }
    }
}
