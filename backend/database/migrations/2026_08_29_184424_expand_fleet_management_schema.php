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
        Schema::table('buses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('manufacturing_year')->nullable();
            $table->string('ownership_status')->default('owned');
            $table->foreignId('current_branch_id')->nullable();
            $table->unsignedBigInteger('mileage_km')->default(0);
            $table->string('gps_device_identifier')->nullable()->unique();
            $table->json('images')->nullable();
        });
        Schema::table('bus_documents', function (Blueprint $table): void {
            $table->foreignId('bus_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('document_type')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->string('file_path')->nullable();
            $table->timestampTz('verified_at')->nullable();
        });
        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->foreignId('bus_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('maintenance_type')->nullable();
            $table->unsignedBigInteger('odometer_km')->nullable();
            $table->dateTimeTz('scheduled_at')->nullable();
            $table->dateTimeTz('completed_at')->nullable();
            $table->string('vendor')->nullable();
            $table->text('notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bus_id');
            $table->dropColumn(['maintenance_type', 'odometer_km', 'scheduled_at', 'completed_at', 'vendor', 'notes']);
        });
        Schema::table('bus_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bus_id');
            $table->dropColumn(['document_type', 'issued_on', 'expires_on', 'file_path', 'verified_at']);
        });
        Schema::table('buses', function (Blueprint $table): void {
            $table->dropColumn('current_branch_id');
            $table->dropUnique(['gps_device_identifier']);
            $table->dropColumn(['manufacturing_year', 'ownership_status', 'mileage_km', 'gps_device_identifier', 'images']);
        });
    }
};
