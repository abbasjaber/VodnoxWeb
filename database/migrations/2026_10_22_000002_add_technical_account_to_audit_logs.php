<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records *which* telephony technical account performed an action, next to the
 * website account (`user_id`) the technical session was opened from.
 *
 * The account id is a foreign key, never a username or a password: the audit
 * trail must show who acted without becoming a place where secrets — or the
 * technical login itself — can be read. A shared technical account identifies
 * the account, not the individual technician, and the log makes no stronger
 * claim than that.
 *
 * Additive and reversible; existing rows keep a null value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('technical_account_id')
                ->nullable()
                ->after('user_id')
                ->constrained('telephony_technical_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technical_account_id');
        });
    }
};
