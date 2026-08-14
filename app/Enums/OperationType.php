<?php

namespace App\Enums;

enum OperationType: string
{
    case Sale = 'sale';
    case Rent = 'rent';
    case SaleRent = 'sale_rent';
    case Presale = 'presale';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Venta',
            self::Rent => 'Renta',
            self::SaleRent => 'Venta/Renta',
            self::Presale => 'Preventa',
        };
    }

    public function includesRent(): bool
    {
        return in_array($this, [self::Rent, self::SaleRent], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $operation) => [$operation->value => $operation->label()])
            ->all();
    }
}
