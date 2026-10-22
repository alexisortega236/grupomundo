<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_change_outbox', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('property_id')->index();
            $table->unsignedBigInteger('revision_number');
            $table->unsignedBigInteger('feed_sequence')->unique();
            $table->string('event_type', 80);
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent()->index();
            $table->timestamp('committed_at')->useCurrent();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['property_id', 'revision_number', 'event_type'],
                'pc_outbox_prop_rev_event_uq',
            );
            $table->index(['status', 'feed_sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_change_outbox');
    }
};
