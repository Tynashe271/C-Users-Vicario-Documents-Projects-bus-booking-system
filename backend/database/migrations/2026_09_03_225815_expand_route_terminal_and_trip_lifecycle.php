<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table): void {
            $table->string('type')->default('terminal');
            $table->boolean('active')->default(true);
            $table->json('operating_hours')->nullable();
            $table->json('contact_information')->nullable();
        });

        Schema::table('routes', function (Blueprint $table): void {
            $table->string('status')->default('active');
            $table->json('border_information')->nullable();
        });

        // `route_stops` already exists as a generic platform-module table (see create_platform_module_tables);
        // promote it with the dedicated relational columns this feature needs, following the same pattern
        // used for employees/maintenance_records/staff_assignments elsewhere in this schema.
        Schema::table('route_stops', function (Blueprint $table): void {
            $table->foreignId('route_id')->nullable()->constrained('routes')->cascadeOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained('terminals');
            $table->unsignedSmallInteger('sequence')->nullable();
            $table->unsignedInteger('arrival_offset_minutes')->nullable();
            $table->unsignedInteger('departure_offset_minutes')->nullable();
            $table->unsignedInteger('stop_duration_minutes')->nullable();
            $table->unique(['route_id', 'sequence']);
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->foreignId('duplicated_from_trip_id')->nullable()->constrained('trips')->nullOnDelete();
            $table->timestampTz('boarding_started_at')->nullable();
            $table->timestampTz('departed_at')->nullable();
            $table->timestampTz('arrived_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('delayed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('delay_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('duplicated_from_trip_id');
            $table->dropColumn(['boarding_started_at', 'departed_at', 'arrived_at', 'completed_at', 'delayed_at', 'cancelled_at', 'delay_reason', 'cancellation_reason']);
        });
        Schema::table('route_stops', function (Blueprint $table): void {
            $table->dropUnique(['route_id', 'sequence']);
            $table->dropConstrainedForeignId('route_id');
            $table->dropConstrainedForeignId('terminal_id');
            $table->dropColumn(['sequence', 'arrival_offset_minutes', 'departure_offset_minutes', 'stop_duration_minutes']);
        });
        Schema::table('routes', fn (Blueprint $table) => $table->dropColumn(['status', 'border_information']));
        Schema::table('terminals', fn (Blueprint $table) => $table->dropColumn(['type', 'active', 'operating_hours', 'contact_information']));
    }
};
