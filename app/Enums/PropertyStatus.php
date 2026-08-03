<?php

namespace App\Enums;

enum PropertyStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Sold = 'sold';
    case Rented = 'rented';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Published => 'Publicada',
            self::Sold => 'Vendida',
            self::Rented => 'Rentada',
            self::Archived => 'Archivada',
        };
    }
}
