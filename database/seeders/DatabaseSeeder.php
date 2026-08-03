<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Amenity;
use App\Models\Property;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@grupomundopatrimonial.test'],
            ['name' => 'Administrador', 'password' => Hash::make('password'), 'role' => 'admin']
        );

        $amenities = collect(['Alberca','Seguridad 24 horas','Elevador','Gimnasio','Terraza','Jardin','Balcon','Cocina equipada','Area de lavado','Pet friendly','Bodega','Roof garden'])
            ->map(fn ($name) => Amenity::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]));

        $items = [
            ['Departamento moderno en Del Valle','sale','Departamento',4850000,'Del Valle',true],
            ['Departamento premium en Xoco','rent','Departamento',32000,'Xoco',true],
            ['Casa residencial en Coyoacan','sale','Casa',9200000,'Coyoacan',true],
            ['Oficina corporativa cerca de Mitikah','rent','Oficina',58000,'Xoco',true],
            ['Departamento nuevo en Narvarte','sale','Departamento',3950000,'Narvarte',false],
            ['Casa remodelada en Portales','sale','Casa',6900000,'Portales',false],
            ['Penthouse con terraza en Del Valle','rent','Departamento',45000,'Del Valle',true],
            ['Local comercial en Roma Norte','rent','Local',39000,'Roma Norte',false],
            ['Terreno patrimonial en San Angel','sale','Terreno',11500000,'San Angel',false],
            ['Oficina privada en Napoles','rent','Oficina',26000,'Napoles',false],
            ['Departamento familiar en Mixcoac','sale','Departamento',4300000,'Mixcoac',false],
            ['Casa con jardin en Tlalpan','sale','Casa',8700000,'Tlalpan',true],
        ];

        foreach ($items as [$title, $operation, $type, $price, $zone, $featured]) {
            $property = Property::updateOrCreate(['slug' => Str::slug($title)], [
                'title' => $title,
                'short_description' => 'Oportunidad seleccionada por Grupo Mundo Patrimonial.',
                'description' => "Propiedad ubicada en {$zone}, con distribucion eficiente, buena iluminacion y condiciones atractivas para vivir, operar o invertir. El expediente se prepara para acompanamiento comercial y documental.",
                'operation_type' => $operation,
                'property_type' => $type,
                'price' => $price,
                'currency' => 'MXN',
                'rent_period' => $operation === 'rent' ? 'mes' : null,
                'street' => 'Direccion disponible previa cita',
                'neighborhood' => $zone,
                'city' => 'Ciudad de Mexico',
                'state' => 'CDMX',
                'bedrooms' => $type === 'Oficina' || $type === 'Local' || $type === 'Terreno' ? null : rand(2, 4),
                'bathrooms' => rand(1, 4),
                'parking_spaces' => rand(0, 3),
                'construction_area' => rand(65, 260),
                'land_area' => rand(80, 360),
                'age' => rand(0, 20).' anos',
                'status' => 'published',
                'is_featured' => $featured,
                'published_at' => now()->subDays(rand(1, 40)),
                'created_by' => $admin->id,
            ]);
            $property->amenities()->sync($amenities->random(4)->pluck('id'));
        }
    }
}
