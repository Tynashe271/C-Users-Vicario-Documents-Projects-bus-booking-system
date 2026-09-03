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
        // Parcel revenue previously generated no commission or wallet entry at all, unlike ticket
        // bookings. Give commissions/settlement_items/wallet_transactions a parcel_id alongside their
        // existing (nullable) booking_id so parcel-sourced revenue flows through the same commission
        // ledger and settlement pipeline as ticket revenue.
        Schema::table('commissions', function (Blueprint $table): void {
            $table->foreignId('parcel_id')->nullable()->unique()->constrained('parcels')->cascadeOnDelete();
        });
        Schema::table('settlement_items', function (Blueprint $table): void {
            $table->foreignId('parcel_id')->nullable()->constrained('parcels')->cascadeOnDelete();
        });
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->foreignId('parcel_id')->nullable()->constrained('parcels')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallet_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('parcel_id'));
        Schema::table('settlement_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('parcel_id'));
        Schema::table('commissions', fn (Blueprint $table) => $table->dropConstrainedForeignId('parcel_id'));
    }
};
