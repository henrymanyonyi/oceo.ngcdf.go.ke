<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regional Coordinators (November 2026, CEO's approval): one per region, originating activity memos
 * from their region's approved workplan. A regional memo and a regional workplan stay under the
 * coordinator's own department (department_id is unchanged, so budgets and reports keep working)
 * and carry the region that scopes them: who sees them, their reference sequence, report compliance
 * and budget position. Additive only; every column is nullable and empty for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('originating_region_id')->nullable()->after('organising_department_id')->constrained('regions')->restrictOnDelete();
            $table->index(['originating_region_id', 'status']);
        });

        Schema::table('department_budgets', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->after('department_id')->constrained()->restrictOnDelete();
            $table->index(['region_id', 'financial_year']);
        });
    }

    /** Rollback: only before any Regional Coordinator account, regional memo or regional workplan exists. */
    public function down(): void
    {
        Schema::table('department_budgets', function (Blueprint $table) {
            $table->dropIndex(['region_id', 'financial_year']);
            $table->dropConstrainedForeignId('region_id');
        });
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['originating_region_id', 'status']);
            $table->dropConstrainedForeignId('originating_region_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('region_id');
        });
    }
};
