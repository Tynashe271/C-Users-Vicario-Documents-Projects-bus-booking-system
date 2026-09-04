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
        // A record of every outbound call to a configured third-party integration (error
        // monitoring, accounting export, mapping/geocoding) — status + the payload sent, so staff
        // can see what was synced and whether it succeeded, without exposing provider credentials
        // themselves. Shaped like every other generic platform-module table (see
        // create_platform_module_tables) so it works through the existing
        // PlatformResourceController without a bespoke controller.
        Schema::create('integration_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->default('active');
            $table->decimal('amount', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->longText('data')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['company_id', 'status']);
            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
