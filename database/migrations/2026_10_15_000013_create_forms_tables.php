<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('thank_you_message_ar')->nullable();
            $table->string('thank_you_message_en')->nullable();
            $table->string('public_token')->unique()->index();
            $table->string('status')->default('draft'); // draft, published, closed
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('max_submissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->json('schema'); // snapshot of fields, options, conditions
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['form_id', 'version']);
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // short_text, long_text, email, phone, number, select, radio, checkbox, date, rating, section
            $table->string('label_ar');
            $table->string('label_en')->nullable();
            $table->string('help_text_ar')->nullable();
            $table->string('help_text_en')->nullable();
            $table->string('placeholder_ar')->nullable();
            $table->string('placeholder_en')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->unsignedInteger('min_length')->nullable();
            $table->unsignedInteger('max_length')->nullable();
            $table->unsignedInteger('min_value')->nullable();
            $table->unsignedInteger('max_value')->nullable();
            $table->unsignedInteger('max_rating')->nullable();
            $table->unsignedInteger('max_selections')->nullable();
            $table->timestamps();
        });

        Schema::create('form_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_field_id')->constrained()->cascadeOnDelete();
            $table->string('label_ar');
            $table->string('label_en')->nullable();
            $table->string('value');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('form_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_field_id')->constrained('form_fields')->cascadeOnDelete();
            $table->string('operator'); // equals, not_equals, contains, greater_than, less_than
            $table->string('value');
            $table->string('action'); // show, hide
            $table->timestamps();
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('answers');
            $table->timestamps();
            $table->index(['form_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_conditions');
        Schema::dropIfExists('form_options');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('form_versions');
        Schema::dropIfExists('forms');
    }
};
