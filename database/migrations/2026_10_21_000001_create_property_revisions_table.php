<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('property_id')->index();
            $table->unsignedBigInteger('revision_number');
            $table->string('event_type', 80);
            $table->boolean('is_visible');
            $table->json('payload');
            $table->timestamp('committed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['property_id', 'revision_number']);
            $table->index(['property_id', 'committed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_revisions');
    }
};
