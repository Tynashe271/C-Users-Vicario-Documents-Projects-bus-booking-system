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
        Schema::table('parcels', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
            $table->string('tracking_number')->nullable()->unique();
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->text('sender_name')->nullable();
            $table->text('sender_phone')->nullable();
            $table->text('receiver_name')->nullable();
            $table->text('receiver_phone')->nullable();
            $table->text('description')->nullable();
            $table->decimal('weight_kg', 9, 2)->nullable();
            $table->decimal('length_cm', 9, 2)->nullable();
            $table->decimal('width_cm', 9, 2)->nullable();
            $table->decimal('height_cm', 9, 2)->nullable();
            $table->boolean('prohibited_items_declared')->default(false);
            $table->string('payment_status')->default('pending');
            $table->string('qr_token', 64)->nullable()->unique();
            $table->string('collection_code_hash', 64)->nullable();
            $table->string('proof_of_collection_path')->nullable();
            $table->timestampTz('collected_at')->nullable();
        });
        Schema::table('parcel_events', function (Blueprint $table): void {
            $table->foreignId('parcel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestampTz('recorded_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parcel_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('terminal_id');
            $table->dropConstrainedForeignId('trip_id');
            $table->dropConstrainedForeignId('parcel_id');
            $table->dropColumn(['event_type', 'notes', 'latitude', 'longitude', 'recorded_at']);
        });
        Schema::table('parcels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trip_id');
            $table->dropConstrainedForeignId('route_id');
            $table->dropUnique(['public_id']);
            $table->dropUnique(['tracking_number']);
            $table->dropUnique(['qr_token']);
            $table->dropColumn(['public_id', 'tracking_number', 'sender_name', 'sender_phone', 'receiver_name', 'receiver_phone', 'description', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'prohibited_items_declared', 'payment_status', 'qr_token', 'collection_code_hash', 'proof_of_collection_path', 'collected_at']);
        });
    }
};
