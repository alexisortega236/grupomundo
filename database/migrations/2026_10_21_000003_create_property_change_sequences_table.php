<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_change_sequences', function (Blueprint $table): void {
            $table->string('name', 80)->primary();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });

        DB::table('property_change_sequences')->insert([
            'name' => 'property_changes',
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('property_change_sequences');
    }
};
