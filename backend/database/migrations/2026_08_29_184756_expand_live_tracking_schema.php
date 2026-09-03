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
        Schema::table('vehicle_locations', function (Blueprint $table): void {
            $table->foreignId('trip_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed_kph', 7, 2)->nullable();
            $table->unsignedSmallInteger('heading')->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->timestampTz('recorded_at')->nullable()->index();
            $table->boolean('route_deviation')->default(false);
            $table->boolean('unexpected_stop')->default(false);
            $table->foreignId('near_terminal_id')->nullable()->constrained('terminals')->nullOnDelete();
            $table->index(['trip_id', 'recorded_at']);
        });
        Schema::table('tracking_links', function (Blueprint $table): void {
            $table->foreignId('trip_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('privacy_precision')->default('approximate');
            $table->timestampTz('revoked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('booking_id');
            $table->dropConstrainedForeignId('trip_id');
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['token_hash', 'privacy_precision', 'revoked_at']);
        });
        Schema::table('vehicle_locations', function (Blueprint $table): void {
            $table->dropIndex(['trip_id', 'recorded_at']);
            $table->dropConstrainedForeignId('near_terminal_id');
            $table->dropConstrainedForeignId('bus_id');
            $table->dropConstrainedForeignId('trip_id');
            $table->dropColumn(['latitude', 'longitude', 'speed_kph', 'heading', 'accuracy_m', 'recorded_at', 'route_deviation', 'unexpected_stop']);
        });
    }
};
