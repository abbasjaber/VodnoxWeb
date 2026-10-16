<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_call_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('direction')->default('inbound'); // inbound, outbound, internal
            $table->string('caller_number')->nullable();
            $table->string('callee_number')->nullable();
            $table->string('status')->default('ringing'); // ringing, answered, unanswered, busy, failed, ended
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('billable_seconds')->nullable();
            $table->string('recording_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('call_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->string('event_type'); // ringing, answered, dtmf, transfer, ended
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['call_id', 'occurred_at']);
        });

        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('mime_type')->default('audio/wav');
            $table->timestamps();
        });

        Schema::create('voicemails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('caller_number')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('file_path');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voicemails');
        Schema::dropIfExists('recordings');
        Schema::dropIfExists('call_events');
        Schema::dropIfExists('calls');
    }
};
