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
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('phone_verified_at')->nullable();
            $table->text('phone_verification_code')->nullable();
            $table->timestampTz('phone_verification_expires_at')->nullable();
            $table->unsignedSmallInteger('phone_verification_attempts')->default(0);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->string('language', 10)->default('en');
            $table->char('currency', 3)->default('USD');
            $table->string('timezone')->default('Africa/Harare');
            $table->string('status')->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('deletion_requested_at')->nullable();
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status']);
            $table->dropColumn(['phone_verified_at', 'phone_verification_code', 'phone_verification_expires_at', 'phone_verification_attempts', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'language', 'currency', 'timezone', 'status', 'last_login_at', 'deletion_requested_at']);
        });
    }
};
