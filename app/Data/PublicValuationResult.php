<?php

namespace App\Data;

class PublicValuationResult
{
    public function __construct(
        public readonly string $source,
        public readonly ?float $estimatedValue,
        public readonly ?float $rangeLow,
        public readonly ?float $rangeHigh,
        public readonly bool $available,
        public readonly ?string $fallbackReason,
    ) {
    }
}
