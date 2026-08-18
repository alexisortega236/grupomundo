<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (! Schema::hasColumn('properties', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('properties', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('uuid')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('properties', 'locality')) {
                $table->string('locality')->nullable()->after('neighborhood');
            }
            if (! Schema::hasColumn('properties', 'municipality')) {
                $table->string('municipality')->nullable()->after('locality')->index();
            }
            if (! Schema::hasColumn('properties', 'land_area_m2')) {
                $table->decimal('land_area_m2', 10, 2)->nullable()->after('longitude');
            }
            if (! Schema::hasColumn('properties', 'construction_area_m2')) {
                $table->decimal('construction_area_m2', 10, 2)->nullable()->after('land_area_m2');
            }
            if (! Schema::hasColumn('properties', 'property_age_years')) {
                $table->unsignedSmallInteger('property_age_years')->nullable()->after('construction_area_m2');
            }
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->index(['latitude', 'longitude'], 'properties_latitude_longitude_index');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_latitude_longitude_index');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn([
                'uuid',
                'locality',
                'municipality',
                'land_area_m2',
                'construction_area_m2',
                'property_age_years',
            ]);
        });
    }
};
