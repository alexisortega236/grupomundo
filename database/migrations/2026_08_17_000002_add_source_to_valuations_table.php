<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->string('source')->default('admin')->after('uuid')->index();
        });
    }

    public function down(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
