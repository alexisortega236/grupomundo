<?php

namespace App\Models;

use App\Enums\ContactRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactRequest extends Model
{
    public static function issueFormToken(?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', (string) $timestamp, (string) config('app.key'));

        return $timestamp.'.'.$signature;
    }

    public static function isFormTokenValid(?string $token): bool
    {
        [$timestamp, $signature] = array_pad(explode('.', (string) $token, 2), 2, null);

        if (! ctype_digit((string) $timestamp) || ! is_string($signature)) {
            return false;
        }

        $age = time() - (int) $timestamp;

        return $age >= 3
            && $age <= 7200
            && hash_equals(
                hash_hmac('sha256', (string) $timestamp, (string) config('app.key')),
                $signature,
            );
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ContactRequestStatus::class,
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
