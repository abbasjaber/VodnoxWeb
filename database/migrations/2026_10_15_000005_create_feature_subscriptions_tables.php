<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // crm, forms, smart_ivr
            $table->string('name_ar');
            $table->string('name_en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('organization_feature_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('inactive'); // inactive, active, suspended
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_customers')->nullable();
            $table->unsignedInteger('max_forms')->nullable();
            $table->unsignedInteger('max_submissions')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('currency')->default('IQD');
            $table->timestamps();
            $table->unique(['organization_id', 'feature_id'], 'org_feature_unique');
            $table->index(['organization_id', 'status']);
        });

        Schema::create('activation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_requests');
        Schema::dropIfExists('organization_feature_subscriptions');
        Schema::dropIfExists('features');
    }
};
