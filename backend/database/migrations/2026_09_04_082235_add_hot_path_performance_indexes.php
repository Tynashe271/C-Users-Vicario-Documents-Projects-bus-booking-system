<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres (this app's production driver) does not automatically index foreign-key columns the
 * way MySQL's InnoDB does — a bare foreignId()->constrained() gets the FK constraint but no
 * index. These are the hot lookup paths that were missing one: company-scoped dashboards/
 * reports/consistency checks, the booking-expiry and seat-lock-cleanup schedules, and payment
 * lookups by booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->index(['company_id', 'status']);
            $table->index('trip_id');
            $table->index(['status', 'payable_until']);
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['booking_id', 'status']);
        });
        Schema::table('trips', function (Blueprint $table): void {
            $table->index('company_id');
        });
        Schema::table('seat_locks', function (Blueprint $table): void {
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'status']);
            $table->dropIndex(['trip_id']);
            $table->dropIndex(['status', 'payable_until']);
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['booking_id', 'status']);
        });
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropIndex(['company_id']);
        });
        Schema::table('seat_locks', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
        });
    }
};
