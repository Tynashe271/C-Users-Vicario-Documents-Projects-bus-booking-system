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
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('cleanliness')->nullable();
            $table->unsignedTinyInteger('comfort')->nullable();
            $table->unsignedTinyInteger('punctuality')->nullable();
            $table->unsignedTinyInteger('driver_professionalism')->nullable();
            $table->unsignedTinyInteger('customer_service')->nullable();
            $table->unsignedTinyInteger('overall_experience')->nullable();
            $table->text('comment')->nullable();
            $table->unique('booking_id');
        });
        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('points_balance')->default(0);
            $table->unsignedBigInteger('lifetime_points')->default(0);
            $table->string('membership_level')->default('Bronze');
            $table->unique('user_id');
        });
        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->foreignId('loyalty_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('points')->default(0);
            $table->string('transaction_type')->default('trip');
            $table->string('reference')->nullable();
            $table->unique(['transaction_type', 'reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->dropUnique(['transaction_type', 'reference']);
            $table->dropConstrainedForeignId('booking_id');
            $table->dropConstrainedForeignId('loyalty_account_id');
            $table->dropColumn(['points', 'transaction_type', 'reference']);
        });
        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropColumn(['points_balance', 'lifetime_points', 'membership_level']);
        });
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique(['booking_id']);
            $table->dropConstrainedForeignId('trip_id');
            $table->dropConstrainedForeignId('booking_id');
            $table->dropColumn(['cleanliness', 'comfort', 'punctuality', 'driver_professionalism', 'customer_service', 'overall_experience', 'comment']);
        });
    }
};
