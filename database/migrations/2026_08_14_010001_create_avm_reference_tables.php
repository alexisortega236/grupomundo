<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->string('provider')->nullable();
            $table->text('description')->nullable();
            $table->string('base_url')->nullable();
            $table->string('update_frequency')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('version');
            $table->string('algorithm')->nullable();
            $table->timestamp('training_started_at')->nullable();
            $table->timestamp('training_completed_at')->nullable();
            $table->unsignedInteger('training_rows')->nullable();
            $table->decimal('mae', 14, 4)->nullable();
            $table->decimal('mape', 8, 4)->nullable();
            $table->decimal('rmse', 14, 4)->nullable();
            $table->decimal('r2', 8, 4)->nullable();
            $table->string('artifact_path')->nullable();
            $table->json('features_json')->nullable();
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['name', 'version']);
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('state_code')->nullable();
            $table->string('state');
            $table->string('municipality_code')->nullable();
            $table->string('municipality');
            $table->string('locality_code')->nullable();
            $table->string('locality')->nullable();
            $table->string('postal_code')->nullable()->index();
            $table->string('neighborhood')->nullable();
            $table->string('ageb_code')->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('geometry')->nullable();
            $table->timestamps();
        });

        Schema::create('pois', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_id')->nullable();
            $table->string('name')->nullable();
            $table->string('category')->index();
            $table->string('subcategory')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->json('geometry')->nullable();
            $table->string('state')->nullable();
            $table->string('municipality')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('socioeconomic_zones', function (Blueprint $table) {
            $table->id();
            $table->string('state_code');
            $table->string('municipality_code');
            $table->string('locality_code')->nullable();
            $table->string('ageb_code')->index();
            $table->json('geometry')->nullable();
            $table->unsignedInteger('population')->nullable();
            $table->decimal('population_density', 14, 4)->nullable();
            $table->unsignedInteger('total_homes')->nullable();
            $table->unsignedInteger('occupied_homes')->nullable();
            $table->decimal('housing_density', 14, 4)->nullable();
            $table->decimal('avg_household_size', 8, 4)->nullable();
            $table->decimal('internet_access_ratio', 8, 4)->nullable();
            $table->decimal('car_ownership_ratio', 8, 4)->nullable();
            $table->decimal('education_index', 8, 4)->nullable();
            $table->decimal('urbanization_score', 8, 4)->nullable();
            $table->decimal('socioeconomic_score', 8, 4)->nullable();
            $table->json('raw_data')->nullable();
            $table->unsignedSmallInteger('data_year')->nullable();
            $table->timestamps();
        });

        Schema::create('market_indices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->string('geographic_level');
            $table->string('state')->nullable();
            $table->string('municipality')->nullable();
            $table->string('property_type')->nullable();
            $table->string('period');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter')->nullable();
            $table->decimal('index_value', 14, 4)->nullable();
            $table->decimal('annual_change', 8, 4)->nullable();
            $table->decimal('quarterly_change', 8, 4)->nullable();
            $table->timestamps();
            $table->index(['state', 'municipality', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_indices');
        Schema::dropIfExists('socioeconomic_zones');
        Schema::dropIfExists('pois');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('model_versions');
        Schema::dropIfExists('data_sources');
    }
};
