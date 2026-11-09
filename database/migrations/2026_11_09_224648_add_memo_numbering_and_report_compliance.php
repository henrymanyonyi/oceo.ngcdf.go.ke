<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic memo references (one sequence per department or region per financial year,
 * App\Services\MemoNumbering) and the CEO's recorded override of the overdue-report
 * submission block (App\Services\ReportCompliance). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memo_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('scope_id');
            $table->string('financial_year', 10);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id', 'financial_year']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->string('memo_number', 100)->nullable()->unique()->after('source_reference');
            $table->timestamp('memo_numbered_at')->nullable()->after('memo_number');
            $table->foreignId('compliance_override_by')->nullable()->after('memo_numbered_at')->constrained('users')->nullOnDelete();
            $table->text('compliance_override_reason')->nullable()->after('compliance_override_by');
            $table->timestamp('compliance_override_at')->nullable()->after('compliance_override_reason');
        });
    }

    /** Rollback: only safe before any memo has been numbered (numbers are permanent once issued). */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compliance_override_by');
            $table->dropUnique(['memo_number']);
            $table->dropColumn(['memo_number', 'memo_numbered_at', 'compliance_override_reason', 'compliance_override_at']);
        });
        Schema::dropIfExists('memo_sequences');
    }
};
