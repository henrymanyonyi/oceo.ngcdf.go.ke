<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital signatures (November 2026). A signature is drawn by its owner on the signature pad and
 * belongs to one account (user_signatures; App\Services\SignatureRegistry). Signing a memo needs the password and a one-time
 * code (signing_challenges, single use). Every signing is an immutable record bound to a hash of the
 * memo's content at that moment (signing_events): the memo PDF shows a signature only where such a
 * record exists and the memo has not changed since. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index(); // pending | active | rejected | revoked | superseded
            $table->string('path');
            $table->string('mime_type', 50);
            $table->char('sha256', 64);
            $table->timestamp('submitted_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('signing_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20);
            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'activity_id', 'action']);
        });

        Schema::create('signing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 20);
            $table->string('role', 40);
            $table->string('signer_name');
            $table->string('designation')->nullable();
            $table->foreignId('user_signature_id')->nullable()->constrained()->nullOnDelete();
            $table->char('content_hash', 64);
            $table->string('verification_code', 20)->unique();
            $table->string('auth_method', 30); // password_and_code | session (step-up switched off)
            $table->foreignId('signing_challenge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('signed_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['activity_id', 'action']);
        });
    }

    /** Rollback: only before anything has been signed; signing records are part of the official record. */
    public function down(): void
    {
        Schema::dropIfExists('signing_events');
        Schema::dropIfExists('signing_challenges');
        Schema::dropIfExists('user_signatures');
    }
};
