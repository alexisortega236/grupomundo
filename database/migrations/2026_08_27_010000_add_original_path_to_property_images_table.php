<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('property_images', 'original_path')) {
            Schema::table('property_images', function (Blueprint $table) {
                $table->string('original_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('property_images', 'original_path')) {
            Schema::table('property_images', function (Blueprint $table) {
                $table->dropColumn('original_path');
            });
        }
    }
};
