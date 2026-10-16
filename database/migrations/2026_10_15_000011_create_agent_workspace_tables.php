<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('name');
            $table->string('phone_number')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('audio_device')->nullable();
            $table->boolean('auto_answer')->default(false);
            $table->boolean('do_not_disturb')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('call_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->text('content');
            $table->timestamps();
        });

        Schema::create('call_wrapups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('outcome')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamps();
        });

        Schema::create('followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->string('status')->default('pending'); // pending, completed, cancelled
            $table->timestamps();
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('description');
            $table->string('priority')->default('medium'); // low, medium, high
            $table->string('status')->default('open'); // open, in_progress, resolved, closed
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('followups');
        Schema::dropIfExists('call_wrapups');
        Schema::dropIfExists('call_notes');
        Schema::dropIfExists('agent_preferences');
        Schema::dropIfExists('contact_favorites');
    }
};
