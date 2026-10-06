<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_requests', function (Blueprint $table): void {
            $table->foreignId('valuation_id')->nullable()->after('property_id')->constrained('valuations')->nullOnDelete();
            $table->index('valuation_id');
        });
    }

    public function down(): void
    {
        Schema::table('contact_requests', function (Blueprint $table): void {
            $table->dropForeign(['valuation_id']);
            $table->dropIndex(['valuation_id']);
            $table->dropColumn('valuation_id');
        });
    }
};
