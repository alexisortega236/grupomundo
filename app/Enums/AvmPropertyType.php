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

    public static function labelFor(?string $value): string
    {
        $value = trim((string) $value);

        return match (strtolower($value)) {
            self::House->value => self::House->label(),
            self::Apartment->value => self::Apartment->label(),
            self::Land->value => self::Land->label(),
            self::Commercial->value => self::Commercial->label(),
            default => $value,
        };
    }
}
