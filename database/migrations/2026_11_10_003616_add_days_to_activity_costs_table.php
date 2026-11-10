<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cost line worked out per person per day (10 November 2026): cost for one person × people × days.
 * The number of days is kept with the line. Additive; empty for every existing line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_costs', function (Blueprint $table) {
            $table->unsignedSmallInteger('days')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('activity_costs', function (Blueprint $table) {
            $table->dropColumn('days');
        });
    }
};
