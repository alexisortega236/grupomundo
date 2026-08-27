<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('properties', 'origin')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->string('origin')->default('commercial')->index()->after('property_type');
            });
        }

        DB::table('properties')->whereNull('origin')->update([
            'origin' => 'commercial',
        ]);

        // A valuation-origin property must have both a valuation relation and
        // at least one known signal from the technical valuation flow.
        DB::table('properties')
            ->whereExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('valuations')
                ->whereColumn('valuations.property_id', 'properties.id'))
            ->where(function ($query) {
                $query->where('title', 'like', 'Valuación %')
                    ->orWhere('slug', 'like', 'valuacion-%')
                    ->orWhere('short_description', 'Propiedad creada desde el módulo de valuación.')
                    ->orWhere('description', 'Propiedad creada desde el módulo de valuación inmobiliaria automatizada.');
            })
            ->update(['origin' => 'valuation']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('properties', 'origin')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropIndex(['origin']);
                $table->dropColumn('origin');
            });
        }
    }
};
