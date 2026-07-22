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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('short_description')->nullable();
            $table->longText('description');
            $table->string('operation_type')->index();
            $table->string('property_type')->index();
            $table->decimal('price', 14, 2)->index();
            $table->string('currency', 3)->default('MXN');
            $table->string('rent_period')->nullable();
            $table->string('street')->nullable();
            $table->string('exterior_number')->nullable();
            $table->string('interior_number')->nullable();
            $table->string('neighborhood')->index();
            $table->string('city')->index();
            $table->string('state')->index();
            $table->string('postal_code')->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable()->index();
            $table->decimal('bathrooms', 4, 1)->nullable()->index();
            $table->unsignedTinyInteger('parking_spaces')->nullable();
            $table->decimal('construction_area', 10, 2)->nullable();
            $table->decimal('land_area', 10, 2)->nullable();
            $table->string('age')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status')->default('draft')->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
