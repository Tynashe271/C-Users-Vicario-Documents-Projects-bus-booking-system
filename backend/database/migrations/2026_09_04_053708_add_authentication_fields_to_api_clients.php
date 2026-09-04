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
        // api_clients already exists as a generic platform-module table (see
        // create_platform_module_tables) and is already reachable read/write through
        // PlatformResourceController for staff (module names containing "api_client" route to
        // security.manage). Promote it with the columns the actual authentication check needs;
        // abilities/allowed_ips stay in the existing `data` column since they're only ever read
        // after the row has already been found by client_id, not searched on.
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->string('client_id')->nullable()->unique();
            $table->string('key_hash')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropUnique(['client_id']);
            $table->dropColumn(['client_id', 'key_hash', 'last_used_at', 'expires_at', 'revoked_at']);
        });
    }
};
