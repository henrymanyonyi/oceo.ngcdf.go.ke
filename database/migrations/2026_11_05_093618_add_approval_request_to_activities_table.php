<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activities.approval_request (5 November 2026), additive only: the "Request for approval" section of the
 * generated memo as the Head of Department (or the officer preparing it) typed it. Null keeps the standard
 * wording, worked out from the total, team, dates and place (App\Services\MemoDocument).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->text('approval_request')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('approval_request');
        });
    }
};
