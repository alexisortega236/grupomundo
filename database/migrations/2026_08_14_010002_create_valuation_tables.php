<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valuations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->decimal('estimated_value', 14, 2)->nullable();
            $table->decimal('estimated_price_m2', 14, 2)->nullable();
            $table->decimal('lower_bound', 14, 2)->nullable();
            $table->decimal('upper_bound', 14, 2)->nullable();
            $table->decimal('confidence_score', 8, 4)->nullable();
            $table->unsignedInteger('comparables_count')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamp('valued_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index('property_id');
        });

        Schema::create('valuation_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valuation_id')->unique()->constrained('valuations')->cascadeOnDelete();
            $table->decimal('construction_land_ratio', 12, 6)->nullable();
            $table->decimal('population_density', 14, 4)->nullable();
            $table->decimal('housing_density', 14, 4)->nullable();
            $table->decimal('socioeconomic_score', 8, 4)->nullable();
            $table->decimal('commercial_density_score', 8, 4)->nullable();
            $table->decimal('services_density_score', 8, 4)->nullable();
            $table->decimal('education_density_score', 8, 4)->nullable();
            $table->decimal('health_density_score', 8, 4)->nullable();
            $table->decimal('nearest_school_distance_m', 12, 2)->nullable();
            $table->decimal('nearest_hospital_distance_m', 12, 2)->nullable();
            $table->decimal('nearest_supermarket_distance_m', 12, 2)->nullable();
            $table->decimal('nearest_park_distance_m', 12, 2)->nullable();
            $table->decimal('nearest_pharmacy_distance_m', 12, 2)->nullable();
            $table->decimal('distance_to_primary_road_m', 12, 2)->nullable();
            $table->decimal('distance_to_city_center_km', 12, 4)->nullable();
            $table->decimal('accessibility_score', 8, 4)->nullable();
            $table->decimal('median_price_m2_500m', 14, 2)->nullable();
            $table->decimal('median_price_m2_1km', 14, 2)->nullable();
            $table->decimal('median_price_m2_3km', 14, 2)->nullable();
            $table->decimal('price_m2_p25', 14, 2)->nullable();
            $table->decimal('price_m2_p50', 14, 2)->nullable();
            $table->decimal('price_m2_p75', 14, 2)->nullable();
            $table->decimal('weighted_comparable_price_m2', 14, 2)->nullable();
            $table->decimal('market_trend_3m', 8, 4)->nullable();
            $table->decimal('market_trend_12m', 8, 4)->nullable();
            $table->json('features_json')->nullable();
            $table->timestamps();
        });

        Schema::create('comparables', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('external_id')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->string('property_type')->index();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('postal_code')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('municipality')->nullable();
            $table->string('state')->nullable();
            $table->decimal('land_area_m2', 10, 2)->nullable();
            $table->decimal('construction_area_m2', 10, 2)->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->decimal('bathrooms', 4, 1)->nullable();
            $table->unsignedTinyInteger('parking_spaces')->nullable();
            $table->unsignedSmallInteger('property_age_years')->nullable();
            $table->decimal('listing_price', 14, 2);
            $table->decimal('listing_price_m2', 14, 2)->nullable();
            $table->date('publication_date')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status')->index();
            $table->string('source_url')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('valuation_comparables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valuation_id')->constrained('valuations')->cascadeOnDelete();
            $table->foreignId('comparable_id')->constrained('comparables')->cascadeOnDelete();
            $table->decimal('distance_m', 12, 2)->nullable();
            $table->decimal('similarity_score', 8, 4)->nullable();
            $table->decimal('weight', 8, 4)->nullable();
            $table->decimal('adjusted_price', 14, 2)->nullable();
            $table->decimal('adjusted_price_m2', 14, 2)->nullable();
            $table->json('adjustments_json')->nullable();
            $table->timestamps();
            $table->unique(['valuation_id', 'comparable_id']);
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('data_sources')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status')->index();
            $table->unsignedInteger('records_found')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('valuation_comparables');
        Schema::dropIfExists('comparables');
        Schema::dropIfExists('valuation_features');
        Schema::dropIfExists('valuations');
    }
};
