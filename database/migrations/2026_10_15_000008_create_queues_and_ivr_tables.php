<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('strategy')->default('ring_all'); // ring_all, least_recent, fewest_calls, random
            $table->unsignedInteger('timeout')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('call_queue_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->unique(['call_queue_id', 'user_id']);
        });

        Schema::create('ivr_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('greeting_ar')->nullable();
            $table->text('greeting_en')->nullable();
            $table->unsignedInteger('timeout')->default(5); // 3-30 seconds
            $table->unsignedInteger('max_attempts')->default(1); // 0-3 retries
            $table->string('invalid_destination_type')->default('voicemail'); // voicemail, hangup, repeat, menu, extension
            $table->unsignedBigInteger('invalid_destination_id')->nullable();
            $table->string('no_input_destination_type')->default('voicemail');
            $table->unsignedBigInteger('no_input_destination_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('ivr_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ivr_menu_id')->constrained()->cascadeOnDelete();
            $table->string('digit', 1); // 0-9
            $table->string('destination_type'); // extension, voicemail, hangup, menu, queue
            $table->unsignedBigInteger('destination_id')->nullable();
            $table->timestamps();
            $table->unique(['ivr_menu_id', 'digit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ivr_options');
        Schema::dropIfExists('ivr_menus');
        Schema::dropIfExists('call_queue_members');
        Schema::dropIfExists('call_queues');
    }
};
