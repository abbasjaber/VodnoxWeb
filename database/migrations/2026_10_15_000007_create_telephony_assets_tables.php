<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->string('label')->nullable();
            $table->string('status')->default('inactive');
            $table->unsignedBigInteger('ivr_menu_id')->nullable();
            $table->unsignedBigInteger('call_queue_id')->nullable();
            $table->string('routing_type')->default('extension'); // extension, ivr, queue, voicemail
            $table->unsignedBigInteger('routing_target_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
        });

        Schema::create('extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('extension');
            $table->string('label')->nullable();
            $table->string('sip_secret')->nullable(); // encrypted in app
            $table->string('status')->default('inactive'); // inactive, active, provisioning, provisioned, failed
            $table->timestamps();
            $table->unique(['organization_id', 'extension']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('status')->default('offline');
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('code')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('extensions');
        Schema::dropIfExists('phone_numbers');
    }
};
