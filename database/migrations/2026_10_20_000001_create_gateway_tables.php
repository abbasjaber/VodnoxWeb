<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GSM gateway (GoIP and similar) devices and their individual lines.
 *
 * Additive and reversible: no existing table is touched, so `migrate:rollback`
 * on this file only drops what it created. A gateway device groups one or more
 * lines; every line owns an independent SIP account and an independent inbound
 * destination, because mixing two SIM cards on one account is exactly what the
 * requirement forbids.
 *
 * State is deliberately split: `enabled` is the desired state, while
 * `provisioning_*` and `registration_*` describe observed reality. A single
 * "green" status column would make an unreachable monitoring source look like a
 * disconnected device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('label');
            $table->string('model')->default('goip-1');   // approved profile, not free text
            $table->unsignedTinyInteger('max_lines')->default(1);
            $table->string('firmware')->nullable();
            $table->text('network_notes')->nullable();    // non-secret notes only

            $table->boolean('enabled')->default(true);

            $table->string('provisioning_status')->default('pending'); // pending|synced|failed|removed|unsupported
            $table->unsignedInteger('provisioning_revision')->default(1);
            $table->text('provisioning_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();

            $table->string('registration_state')->default('unknown'); // unknown|registered|no_contact|auth_failure|unreachable
            $table->timestamp('registration_observed_at')->nullable();
            $table->string('monitoring_source')->nullable();          // agent|<manual>|null

            $table->string('test_status')->default('untested');       // untested|manual|verified|failed
            $table->text('test_message')->nullable();
            $table->timestamp('test_checked_at')->nullable();

            $table->text('agent_capabilities')->nullable();           // JSON, non-secret

            $table->timestamps();

            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('gateway_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_device_id')->constrained('gateway_devices')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('line_index');            // 1..max_lines on this device
            $table->string('label')->nullable();
            $table->string('sim_number')->nullable();             // descriptive only, never an identity

            // Inbound route for this line (generated; never the employee number).
            $table->string('inbound_number');
            $table->foreignId('inbound_extension_id')->nullable()->constrained('extensions')->nullOnDelete();

            // Independent SIP identity, globally unique, never an employee number.
            $table->string('sip_username')->unique();
            $table->text('sip_password');                         // encrypted at rest, hidden from JSON
            $table->string('sip_domain')->nullable();
            $table->string('sip_host')->nullable();
            $table->unsignedSmallInteger('sip_port')->nullable();
            $table->string('sip_transport')->default('udp');

            $table->boolean('enabled')->default(true);

            $table->string('provisioning_status')->default('pending');
            $table->unsignedInteger('provisioning_revision')->default(1);
            $table->text('provisioning_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();

            $table->string('registration_state')->default('unknown');
            $table->timestamp('registration_observed_at')->nullable();
            $table->text('registration_meta')->nullable();

            $table->string('test_status')->default('untested');
            $table->text('test_message')->nullable();
            $table->timestamp('test_checked_at')->nullable();

            $table->timestamps();

            $table->unique(['gateway_device_id', 'line_index']);
            $table->unique(['organization_id', 'inbound_number']);
            $table->index(['organization_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_lines');
        Schema::dropIfExists('gateway_devices');
    }
};
