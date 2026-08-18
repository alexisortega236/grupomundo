<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valuation_model_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valuation_id')->constrained('valuations')->cascadeOnDelete();
            $table->string('model_name');
            $table->string('model_version')->nullable();
            $table->string('status')->default('pending')->index();
            $table->boolean('eligible')->default(false);
            $table->decimal('estimated_value', 14, 2)->nullable();
            $table->decimal('range_low', 14, 2)->nullable();
            $table->decimal('range_high', 14, 2)->nullable();
            $table->string('confidence')->nullable();
            $table->json('request_json')->nullable();
            $table->json('response_json')->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedInteger('execution_ms')->nullable();
            $table->timestamps();

            $table->index(['valuation_id', 'model_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valuation_model_predictions');
    }
};
