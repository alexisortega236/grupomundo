<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postal_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('state');
            $table->string('state_code', 8)->nullable();
            $table->string('municipality');
            $table->string('municipality_code', 8)->nullable();
            $table->string('settlement');
            $table->string('settlement_type')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('zone')->nullable();
            $table->string('source')->default('sepomex');
            $table->timestamps();

            $table->index(['state', 'municipality']);
            $table->index(['state', 'municipality', 'settlement']);
            $table->index('postal_code');
            $table->unique(
                ['source', 'state_code', 'municipality_code', 'settlement', 'postal_code'],
                'postal_settlements_identity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postal_settlements');
    }
};
