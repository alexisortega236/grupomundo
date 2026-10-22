<?php

namespace App\Exceptions;

use RuntimeException;

class PropertyChangeCursorException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus = 422,
        string $message = 'The property change cursor is invalid.',
    ) {
        parent::__construct($message);
    }
}
