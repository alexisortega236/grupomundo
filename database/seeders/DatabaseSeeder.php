<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@grupomundopatrimonial.test'],
            ['name' => 'Administrador', 'password' => Hash::make('password'), 'role' => 'admin', 'email_verified_at' => now()]
        );

        $amenities = collect(['Alberca','Seguridad 24 horas','Elevador','Gimnasio','Terraza','Jardín','Balcón','Cocina equipada','Área de lavado','Pet friendly','Bodega','Roof garden'])
            ->mapWithKeys(fn ($name) => [$name => Amenity::updateOrCreate(['slug' => Str::slug($name)], ['name' => $name])]);

        $items = [
            ['Departamento moderno en Del Valle','sale','Departamento',6850000,'Del Valle Centro','Benito Juárez','Ciudad de México',2,2,105,true],
            ['Departamento premium en Xoco','rent','Departamento',32000,'Xoco','Benito Juárez','Ciudad de México',2,2,98,true],
            ['Casa residencial en Coyoacán','sale','Casa',11900000,'Barrio Santa Catarina','Coyoacán','Ciudad de México',3,3.5,240,true],
            ['Oficina corporativa cerca de Mitikah','rent','Oficina',55000,'Xoco','Benito Juárez','Ciudad de México',0,2,140,false],
            ['Departamento nuevo en Narvarte','sale','Departamento',5250000,'Narvarte Poniente','Benito Juárez','Ciudad de México',2,2,88,true],
            ['Casa remodelada en Portales','sale','Casa',8900000,'Portales Norte','Benito Juárez','Ciudad de México',4,3,210,false],
            ['Loft ejecutivo en Roma Norte','rent','Departamento',28500,'Roma Norte','Cuauhtémoc','Ciudad de México',1,1,62,false],
            ['Penthouse con roof garden en Nápoles','sale','Departamento',9800000,'Nápoles','Benito Juárez','Ciudad de México',3,3,168,true],
            ['Casa familiar en San Ángel','sale','Casa',14500000,'San Ángel','Álvaro Obregón','Ciudad de México',4,4,310,false],
            ['Oficina flexible en Polanco','rent','Oficina',76000,'Polanco','Miguel Hidalgo','Ciudad de México',0,2,180,false],
            ['Departamento iluminado en Condesa','rent','Departamento',41000,'Condesa','Cuauhtémoc','Ciudad de México',2,2,120,true],
            ['Casa con jardín en Tlalpan','sale','Casa',10200000,'Tlalpan Centro','Tlalpan','Ciudad de México',3,3,260,false],
        ];

        foreach ($items as $i => [$title, $operation, $type, $price, $neighborhood, $city, $state, $beds, $baths, $area, $featured]) {
            $property = Property::updateOrCreate(['slug' => Str::slug($title)], [
                'title' => $title,
                'short_description' => 'Propiedad seleccionada por Grupo Mundo Patrimonial.',
                'description' => "Excelente {$type} en {$neighborhood}, con ubicación estratégica, espacios funcionales y documentación preparada para una operación segura.",
                'operation_type' => $operation,
                'property_type' => $type,
                'price' => $price,
                'currency' => 'MXN',
                'rent_period' => $operation === 'rent' ? 'mes' : null,
                'street' => 'Calle Patrimonial',
                'exterior_number' => (string) (100 + $i),
                'neighborhood' => $neighborhood,
                'city' => $city,
                'state' => $state,
                'bedrooms' => $beds,
                'bathrooms' => $baths,
                'parking_spaces' => $type === 'Oficina' ? 2 : 1,
                'construction_area' => $area,
                'land_area' => $area + 25,
                'age' => $i % 2 ? '5 años' : 'Nueva',
                'status' => 'published',
                'is_featured' => $featured,
                'published_at' => now()->subDays($i),
                'created_by' => $admin->id,
            ]);
            $property->amenities()->sync($amenities->random(4)->pluck('id'));
        }

        Property::updateOrCreate(['slug' => 'borrador-de-prueba'], [
            'title' => 'Borrador de prueba', 'description' => 'No visible públicamente.', 'operation_type' => 'sale', 'property_type' => 'Casa', 'price' => 1, 'neighborhood' => 'Privada', 'city' => 'Ciudad de México', 'state' => 'Ciudad de México', 'status' => 'draft', 'created_by' => $admin->id,
        ]);
    }
}
