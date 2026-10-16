<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('strategy')->default('simultaneous'); // simultaneous, sequential
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('ring_duration')->default(30); // 5-120 seconds
            $table->string('no_answer_type')->default('voicemail'); // voicemail, message, hangup, agent
            $table->unsignedBigInteger('no_answer_target_id')->nullable();
            $table->unsignedInteger('fallback_ring_duration')->default(20);
            $table->string('outside_hours_type')->default('voicemail'); // voicemail, ivr, hangup
            $table->unsignedBigInteger('outside_hours_target_id')->nullable();
            $table->timestamps();
        });

        Schema::create('routing_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day'); // 0=Sunday ... 6=Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });

        Schema::create('routing_schedule_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order')->default(1);
            $table->unsignedInteger('ring_duration')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_schedule_targets');
        Schema::dropIfExists('routing_schedule_days');
        Schema::dropIfExists('routing_schedules');
    }
};
