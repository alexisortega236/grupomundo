<?php

namespace App\Http\Resources\Api\V1;

use App\Exceptions\PublicApiConfigurationException;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PropertyImageResource extends JsonResource
{
    public static function isApiSafe(object $image, mixed $request = null): bool
    {
        $path = $image->card_path ?: $image->path;

        if (! is_string($path) || trim($path) === '') {
            self::logOmittedImage($image, 'missing_path');

            return false;
        }

        try {
            $url = Storage::disk('public')->url($path);
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $host = parse_url($url, PHP_URL_HOST);
        } catch (Throwable) {
            self::logOmittedImage($image, 'url_generation_failed');

            return false;
        }

        if ($scheme !== 'https' || ! is_string($host) || $host === '') {
            self::logOmittedImage($image, 'non_https_url');

            return false;
        }

        return true;
    }

    public function toArray($request): array
    {
        $path = $this->card_path ?: $this->path;
        $url = Storage::disk('public')->url($path);

        if (! self::isApiSafe($this->resource, $request)) {
            throw new PublicApiConfigurationException;
        }

        return [
            'external_id' => $this->id,
            'url' => $url,
            'position' => $this->position,
            'is_cover' => (bool) $this->is_cover,
            'alt_text' => $this->alt_text,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    private static function logOmittedImage(object $image, string $reason): void
    {
        Log::warning('Public API omitted an invalid property image.', [
            'image_id' => $image->id ?? null,
            'property_id' => $image->property_id ?? null,
            'reason' => $reason,
        ]);
    }
}
