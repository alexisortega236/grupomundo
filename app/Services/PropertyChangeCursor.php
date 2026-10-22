<?php

namespace App\Services;

use App\Exceptions\PropertyChangeCursorException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class PropertyChangeCursor
{
    public function encode(int $after): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'after' => $after,
            'expires_at' => now()->addSeconds(config('integration.property_changes.cursor_ttl_seconds', 86400))->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    public function decode(?string $encoded): int
    {
        if ($encoded === null || $encoded === '') {
            throw new PropertyChangeCursorException('cursor_invalid');
        }

        try {
            $payload = json_decode(Crypt::decryptString($encoded), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new PropertyChangeCursorException('cursor_invalid');
        }

        if (($payload['version'] ?? null) !== 1
            || ! is_int($payload['after'] ?? null)
            || $payload['after'] < 0
            || ! is_string($payload['expires_at'] ?? null)) {
            throw new PropertyChangeCursorException('cursor_invalid');
        }

        try {
            $expiresAt = new \DateTimeImmutable($payload['expires_at']);
        } catch (\Exception) {
            throw new PropertyChangeCursorException('cursor_invalid');
        }

        if ($expiresAt <= new \DateTimeImmutable('now')) {
            throw new PropertyChangeCursorException(
                'cursor_expired',
                410,
                'The property change cursor has expired; run a full reconciliation.',
            );
        }

        return $payload['after'];
    }
}
