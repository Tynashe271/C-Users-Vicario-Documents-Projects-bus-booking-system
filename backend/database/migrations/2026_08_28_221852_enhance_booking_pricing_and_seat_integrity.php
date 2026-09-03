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
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('booking_type')->default('single');
            $table->string('source')->default('web');
            $table->uuid('journey_group')->nullable()->index();
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('taxes', 12, 2)->default(0);
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->longText('fare_breakdown')->nullable();
            $table->timestampTz('payable_until')->nullable();
        });
        Schema::table('booking_passengers', function (Blueprint $table): void {
            $table->foreignId('trip_id')->nullable()->after('booking_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('confirmed');
            $table->longText('details')->nullable();
            $table->unique(['trip_id', 'seat_id']);
        });
        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('status')->default('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('status'));
        Schema::table('booking_passengers', function (Blueprint $table): void {
            $table->dropUnique(['trip_id', 'seat_id']);
            $table->dropConstrainedForeignId('trip_id');
            $table->dropColumn(['status', 'details']);
        });
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['journey_group']);
            $table->dropColumn(['booking_type', 'source', 'journey_group', 'discount', 'taxes', 'platform_fee', 'fare_breakdown', 'payable_until']);
        });
    }
};
