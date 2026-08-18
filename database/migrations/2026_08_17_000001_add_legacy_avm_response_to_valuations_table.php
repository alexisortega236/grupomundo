<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('comparables_count');
            $table->string('zone_inferred')->nullable()->after('currency');
            $table->json('avm_response_json')->nullable()->after('zone_inferred');
        });
    }

    public function down(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->dropColumn(['currency', 'zone_inferred', 'avm_response_json']);
        });
    }
};
