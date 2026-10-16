<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the real-telephony columns that keep the distinct states apart:
 *
 *  - Laravel account        -> users (unchanged)
 *  - Visible internal number-> extensions.extension
 *  - Globally unique SIP id -> extensions.sip_username
 *  - Provisioning state     -> extensions.provisioning_status / organizations.telephony_status
 *  - Device registration    -> extensions.last_registered_at
 *  - Live call state        -> calls.* (unchanged) + calls.sip_call_id
 *
 * Purely additive: every column is nullable or defaulted, so existing rows and
 * running deployments keep working without a rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('telephony_context', 64)->nullable()->unique()->after('language');
            $table->string('telephony_realm', 128)->nullable()->after('telephony_context');
            $table->string('telephony_status', 20)->default('pending')->after('telephony_realm');
            $table->text('telephony_error')->nullable()->after('telephony_status');
            $table->timestamp('telephony_synced_at')->nullable()->after('telephony_error');
        });

        Schema::table('extensions', function (Blueprint $table) {
            $table->string('sip_username', 64)->nullable()->unique()->after('sip_secret');
            $table->text('sip_password')->nullable()->after('sip_username');
            $table->string('sip_domain', 128)->nullable()->after('sip_password');
            $table->string('provisioning_status', 20)->default('pending')->after('status');
            $table->text('provisioning_error')->nullable()->after('provisioning_status');
            $table->timestamp('provisioned_at')->nullable()->after('provisioning_error');
            $table->timestamp('disabled_at')->nullable()->after('provisioned_at');
            $table->timestamp('last_registered_at')->nullable()->after('disabled_at');
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->string('sip_call_id', 128)->nullable()->index()->after('external_call_id');
            $table->string('caller_extension', 32)->nullable()->after('callee_number');
            $table->string('callee_extension', 32)->nullable()->after('caller_extension');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['sip_call_id']);
            $table->dropColumn(['sip_call_id', 'caller_extension', 'callee_extension']);
        });

        Schema::table('extensions', function (Blueprint $table) {
            $table->dropUnique(['sip_username']);
            $table->dropColumn([
                'sip_username', 'sip_password', 'sip_domain', 'provisioning_status',
                'provisioning_error', 'provisioned_at', 'disabled_at', 'last_registered_at',
            ]);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['telephony_context']);
            $table->dropColumn([
                'telephony_context', 'telephony_realm', 'telephony_status',
                'telephony_error', 'telephony_synced_at',
            ]);
        });
    }
};
