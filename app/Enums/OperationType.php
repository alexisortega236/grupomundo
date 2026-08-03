<?php

namespace App\Enums;

enum OperationType: string
{
    case Sale = 'sale';
    case Rent = 'rent';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Venta',
            self::Rent => 'Renta',
        };
    }
}
