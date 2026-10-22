<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The independent technical sign-in for the "Telephony Technical Settings"
 * section — one account per organization, managed only by the super admin.
 *
 * This is a *separate* identity from the organization administrator's website
 * account, and deliberately so:
 *
 *  - the password is stored with Laravel's one-way Hash (bcrypt), never in
 *    plain text and never with a reversible cipher;
 *  - the plain password is shown once, at creation, to the super admin and is
 *    never retrievable afterwards;
 *  - `session_version` is bumped on password reset / disable so every session
 *    opened with the previous secret is invalidated server-side.
 *
 * Additive and reversible: no existing table is touched, so `migrate:rollback`
 * on this file only drops what it created and no organization, user, device or
 * SIP credential is disturbed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telephony_technical_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Globally unique so the technical sign-in is unambiguous, and
            // deliberately unrelated to the organization admin's username.
            $table->string('username')->unique();
            $table->string('password');                       // Laravel Hash, one-way

            $table->boolean('active')->default(true);

            // Bumped on every password reset / disable; a stale session that
            // still carries the old value is rejected by the middleware.
            $table->unsignedInteger('session_version')->default(1);

            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telephony_technical_accounts');
    }
};
