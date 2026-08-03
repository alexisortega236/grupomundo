<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('property_images', function (Blueprint $table) {
            $table->string('card_path')->nullable()->after('path');
            $table->string('thumb_path')->nullable()->after('card_path');
            $table->string('original_filename')->nullable()->after('alt_text');
            $table->unsignedInteger('size_kb')->nullable()->after('original_filename');
            $table->unsignedInteger('width')->nullable()->after('size_kb');
            $table->unsignedInteger('height')->nullable()->after('width');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_images', function (Blueprint $table) {
            $table->dropColumn([
                'card_path',
                'thumb_path',
                'original_filename',
                'size_kb',
                'width',
                'height',
            ]);
        });
    }
};
