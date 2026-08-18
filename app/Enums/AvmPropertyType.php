<?php

namespace App\Enums;

enum AvmPropertyType: string
{
    case House = 'house';
    case Apartment = 'apartment';
    case Land = 'land';
    case Commercial = 'commercial';

    public function label(): string
    {
        return match ($this) {
            self::House => 'Casa',
            self::Apartment => 'Departamento',
            self::Land => 'Terreno',
            self::Commercial => 'Comercial',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type) => [$type->value => $type->label()])
            ->all();
    }
}
