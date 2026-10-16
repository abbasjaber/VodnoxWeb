<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telephony_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('connection_method')->default('ami'); // ami, ari, http
            $table->string('ami_username')->nullable();
            $table->text('ami_secret')->nullable(); // encrypted
            $table->string('status')->default('disconnected');
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // asterisk_ami, asterisk_ari, recording_storage, payment, smtp
            $table->string('name');
            $table->json('config')->nullable(); // encrypted values stored separately
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('provisioning_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // provision_org, provision_extension, provision_ivr, etc.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->string('status')->default('pending'); // pending, processing, synced, failed
            $table->string('idempotency_key')->unique()->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->string('signature')->nullable();
            $table->string('status')->default('received'); // received, processed, failed, duplicate
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['event_type', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->timestamps();
        });

        Schema::create('contact_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->text('message');
            $table->string('status')->default('new'); // new, read, replied, closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_inquiries');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('provisioning_jobs');
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('telephony_servers');
    }
};
