<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PropertyVideoService
{
    public function sync(Property $property, array $data, ?TransactionSideEffects $effects = null): void
    {
        $ownsEffects = $effects === null;
        $effects ??= new TransactionSideEffects();

        try {
            foreach ($data['delete_videos'] ?? [] as $videoId) {
                $video = $property->videos()->find($videoId);
                if ($video) {
                    $effects->deleteAfterCommit([$video->path]);
                    $video->delete();
                }
            }

            $createdPaths = [];
            $position = (int) $property->videos()->max('position');

            foreach ($data['videos'] ?? [] as $index => $video) {
                if (! $video instanceof UploadedFile) {
                    continue;
                }

                $filename = "{$effects->operationId()}-{$index}.".strtolower($video->getClientOriginalExtension() ?: 'mp4');
                $path = $video->storeAs(
                    "properties/{$property->id}/videos",
                    $filename,
                    'public'
                );
                $createdPaths[] = $path;
                $effects->stage($path);

                $property->videos()->create([
                    'path' => $path,
                    'original_filename' => $video->getClientOriginalName(),
                    'mime_type' => $video->getMimeType(),
                    'size_bytes' => $video->getSize(),
                    'position' => ++$position,
                ]);
            }

            if ($ownsEffects) {
                $effects->committed();
            }
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($createdPaths);
            if ($ownsEffects) {
                $effects->rolledBack();
            }
            throw $exception;
        }
    }
}
