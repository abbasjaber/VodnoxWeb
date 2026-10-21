<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line inbound routing for GSM gateway lines.
 *
 * Additive only: nothing that already ran is touched, and the existing
 * `gateway_lines.inbound_extension_id` column is *kept* and copied into the new
 * `gateway_line_routes.default_destination_extension_id`, so every line that is
 * already in production keeps routing to exactly the same extension.
 *
 * The routing model is deliberately small and ordered:
 *   1. a temporary permanent forward, when switched on;
 *   2. a dated exception (holiday / leave) whose range covers the moment;
 *   3. the weekly business-hours windows plus the weekly weekend days;
 *   4. the default destination when no rule matches at all.
 *
 * The same rows feed both the live Asterisk dialplan and the administrator's
 * "preview routing" button, so the preview can never disagree with the call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_line_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_line_id')->constrained()->cascadeOnDelete();

            // Every destination is a reference, never a copied number, so it can
            // never drift outside the owning organization.
            $table->unsignedBigInteger('default_destination_extension_id')->nullable();
            $table->unsignedBigInteger('outside_hours_destination_extension_id')->nullable();
            $table->unsignedBigInteger('weekend_destination_extension_id')->nullable();
            $table->unsignedBigInteger('fallback_destination_extension_id')->nullable();

            // The "vacation / cover" switch: permanent forward, enabled or not.
            $table->boolean('temporary_forward_enabled')->default(false);
            $table->unsignedBigInteger('temporary_forward_extension_id')->nullable();

            $table->timestamps();

            $table->unique('gateway_line_id');
            $table->index('organization_id');

            // Explicit, short constraint names: MySQL caps identifiers at 64
            // characters and the names Laravel derives from these columns are
            // longer than that.
            $table->foreign('default_destination_extension_id', 'gw_route_default_ext_fk')
                ->references('id')->on('extensions')->nullOnDelete();
            $table->foreign('outside_hours_destination_extension_id', 'gw_route_outside_ext_fk')
                ->references('id')->on('extensions')->nullOnDelete();
            $table->foreign('weekend_destination_extension_id', 'gw_route_weekend_ext_fk')
                ->references('id')->on('extensions')->nullOnDelete();
            $table->foreign('fallback_destination_extension_id', 'gw_route_fallback_ext_fk')
                ->references('id')->on('extensions')->nullOnDelete();
            $table->foreign('temporary_forward_extension_id', 'gw_route_temp_ext_fk')
                ->references('id')->on('extensions')->nullOnDelete();
        });

        Schema::create('gateway_line_business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_line_route_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day'); // 0 = Sunday ... 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index('gateway_line_route_id');
        });

        Schema::create('gateway_line_weekend_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_line_route_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day'); // 0 = Sunday ... 6 = Saturday
            $table->timestamps();

            $table->unique(['gateway_line_route_id', 'day']);
        });

        Schema::create('gateway_line_date_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_line_route_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on'); // inclusive: the whole end day is covered
            $table->foreignId('destination_extension_id')->nullable()
                ->constrained('extensions')->nullOnDelete();
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['gateway_line_route_id', 'starts_on', 'ends_on'], 'gw_route_exceptions_range_idx');
        });

        // Preserve today's routing: copy the single inbound destination of each
        // existing line into the new default destination.
        DB::table('gateway_lines')
            ->orderBy('id')
            ->chunk(200, function ($lines): void {
                $now = now();

                $rows = [];

                foreach ($lines as $line) {
                    $rows[] = [
                        'organization_id' => $line->organization_id,
                        'gateway_line_id' => $line->id,
                        'default_destination_extension_id' => $line->inbound_extension_id,
                        'temporary_forward_enabled' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('gateway_line_routes')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_line_date_exceptions');
        Schema::dropIfExists('gateway_line_weekend_days');
        Schema::dropIfExists('gateway_line_business_hours');
        Schema::dropIfExists('gateway_line_routes');
    }
};
