<?php

namespace Database\Factories;

use App\Enums\OperationType;
use App\Enums\PropertyStatus;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $title = $this->faker->randomElement(['Departamento moderno', 'Casa residencial', 'Oficina corporativa']).' en '.$this->faker->randomElement(['Del Valle', 'Xoco', 'Coyoacan', 'Narvarte']),
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(100, 999),
            'short_description' => 'Propiedad seleccionada para vivir o invertir con excelente ubicacion.',
            'description' => 'Espacio funcional con acabados modernos, buena iluminacion natural y conectividad hacia servicios principales.',
            'operation_type' => $this->faker->randomElement(OperationType::cases())->value,
            'property_type' => $this->faker->randomElement(['Departamento', 'Casa', 'Oficina']),
            'price' => $this->faker->numberBetween(18000, 9500000),
            'currency' => 'MXN',
            'rent_period' => 'mes',
            'neighborhood' => $this->faker->randomElement(['Del Valle', 'Xoco', 'Coyoacan', 'Narvarte', 'Portales']),
            'city' => 'Ciudad de Mexico',
            'state' => 'CDMX',
            'bedrooms' => $this->faker->numberBetween(1, 4),
            'bathrooms' => $this->faker->randomFloat(1, 1, 4),
            'parking_spaces' => $this->faker->numberBetween(0, 3),
            'construction_area' => $this->faker->numberBetween(55, 280),
            'land_area' => $this->faker->numberBetween(60, 350),
            'status' => PropertyStatus::Published->value,
            'is_featured' => $this->faker->boolean(35),
            'published_at' => now(),
        ];
    }
}
