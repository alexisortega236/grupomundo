<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Property;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->unsignedTinyInteger('full_bathrooms')->nullable()->after('bathrooms');
            $table->unsignedTinyInteger('half_bathrooms')->nullable()->after('full_bathrooms');
        });

        DB::table('properties')->select(['id', 'bathrooms'])->whereNotNull('bathrooms')->orderBy('id')->each(function (object $property): void {
            $breakdown = Property::bathroomBreakdownFromLegacy($property->bathrooms);
            if ($breakdown) {
                DB::table('properties')->where('id', $property->id)->update([
                    ...$breakdown,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn(['full_bathrooms', 'half_bathrooms']);
        });
    }
};
