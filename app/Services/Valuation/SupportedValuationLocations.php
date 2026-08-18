<?php

namespace App\Services\Valuation;

class SupportedValuationLocations
{
    /**
     * Municipalities currently represented by the AVM listings pipeline.
     */
    public function municipalities(): array
    {
        return [
            'Cuernavaca' => 'Cuernavaca',
            'Cuautla' => 'Cuautla',
            'Jiutepec' => 'Jiutepec',
            'Yautepec' => 'Yautepec',
            'Atlatlahucan' => 'Atlatlahucan',
            'Ayala' => 'Ayala',
            'Temixco' => 'Temixco',
            'Emiliano Zapata' => 'Emiliano Zapata',
            'Xochitepec' => 'Xochitepec',
        ];
    }

    public function centers(): array
    {
        return [
            'Cuernavaca' => ['lat' => 18.9242, 'lng' => -99.2216],
            'Cuautla' => ['lat' => 18.8126, 'lng' => -98.9548],
            'Jiutepec' => ['lat' => 18.8814, 'lng' => -99.1778],
            'Yautepec' => ['lat' => 18.8833, 'lng' => -99.0667],
            'Atlatlahucan' => ['lat' => 18.9347, 'lng' => -98.8986],
            'Ayala' => ['lat' => 18.7667, 'lng' => -98.9833],
            'Temixco' => ['lat' => 18.8525, 'lng' => -99.2256],
            'Emiliano Zapata' => ['lat' => 18.8406, 'lng' => -99.1847],
            'Xochitepec' => ['lat' => 18.7808, 'lng' => -99.2303],
        ];
    }
}
