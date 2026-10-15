<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        $now = now();
        foreach (['Casa', 'Departamento', 'Oficina', 'Local', 'Consultorio', 'Terreno', 'Bodega'] as $order => $name) {
            DB::table('property_types')->insert([
                'name' => $name,
                'slug' => Str::slug($name),
                'sort_order' => $order + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('properties', function (Blueprint $table): void {
            $table->foreignId('property_type_id')->nullable()->after('property_type')->constrained('property_types')->nullOnDelete();
        });

        DB::table('properties')->select(['id', 'property_type'])->whereNotNull('property_type')->orderBy('id')->each(function (object $property): void {
            $typeId = DB::table('property_types')->where('name', $property->property_type)->value('id');
            if ($typeId) {
                DB::table('properties')->where('id', $property->id)->update(['property_type_id' => $typeId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropForeign(['property_type_id']);
            $table->dropColumn('property_type_id');
        });

        Schema::dropIfExists('property_types');
    }
};
