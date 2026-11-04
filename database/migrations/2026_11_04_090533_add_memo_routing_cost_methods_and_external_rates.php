<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity memo enhancements (4 November 2026), additive only:
 *
 *  - activities.submission_route: how the Head of Department sent the memo (App\Enums\SubmissionRoute):
 *    through the COS/ACOS office, or directly to the CEO. Null on memos submitted before this, which
 *    follow the review route as before.
 *  - activities.category_other: the category typed in when "Other (specify)" is chosen.
 *  - activity_categories.requires_detail: the category that asks for that text.
 *  - activity_costs.calculation_method: per person (unit cost × number of people) or a fixed amount
 *    for the activity (App\Enums\CostMethod). Null on older lines, which are fixed amounts.
 *  - activity_participants.role_other: the role typed in when "Other (specify)" is chosen.
 *  - activity_participants.external_designation, rate_source, rate_amount, rate_unit: an external
 *    participant's designation and payment rate (a DSA scale, kept in job_grade, or a custom amount
 *    per night, per day or once), paid by a computed cost line (App\Services\DsaCalculator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('submission_route', 20)->nullable()->after('review_office');
            $table->string('category_other', 150)->nullable()->after('activity_category_id');
        });

        Schema::table('activity_categories', function (Blueprint $table) {
            $table->boolean('requires_detail')->default(false)->after('name');
        });

        Schema::table('activity_costs', function (Blueprint $table) {
            $table->string('calculation_method', 20)->nullable()->after('description');
        });

        Schema::table('activity_participants', function (Blueprint $table) {
            $table->string('role_other', 100)->nullable()->after('role');
            $table->string('external_designation')->nullable()->after('external_organisation');
            $table->string('rate_source', 10)->nullable()->after('job_grade');
            $table->decimal('rate_amount', 15, 2)->nullable()->after('rate_source');
            $table->string('rate_unit', 10)->nullable()->after('rate_amount');
        });
    }

    public function down(): void
    {
        Schema::table('activity_participants', function (Blueprint $table) {
            $table->dropColumn(['role_other', 'external_designation', 'rate_source', 'rate_amount', 'rate_unit']);
        });

        Schema::table('activity_costs', function (Blueprint $table) {
            $table->dropColumn('calculation_method');
        });

        Schema::table('activity_categories', function (Blueprint $table) {
            $table->dropColumn('requires_detail');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['submission_route', 'category_other']);
        });
    }
};
