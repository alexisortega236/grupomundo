<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_types', function (Blueprint $table): void {
            $table->string('classification', 30)->nullable()->after('slug')->index();
        });

        $classifications = [
            'Casa' => 'residential',
            'Departamento' => 'residential',
            'Oficina' => 'commercial',
            'Local' => 'commercial',
            'Consultorio' => 'commercial',
            'Bodega' => 'commercial',
            'Terreno' => 'land',
        ];

        foreach ($classifications as $name => $classification) {
            DB::table('property_types')
                ->where('name', $name)
                ->update(['classification' => $classification]);
        }
    }

    public function down(): void
    {
        Schema::table('property_types', function (Blueprint $table): void {
            $table->dropIndex(['classification']);
            $table->dropColumn('classification');
        });
    }
};
