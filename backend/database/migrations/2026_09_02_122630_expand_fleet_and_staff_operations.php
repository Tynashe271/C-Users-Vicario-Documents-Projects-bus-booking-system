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
        Schema::table('buses', fn (Blueprint $table) => $table->foreign('current_branch_id')->references('id')->on('company_branches')->nullOnDelete());
        Schema::table('employees', fn (Blueprint $table) => $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete());

        Schema::table('buses', function (Blueprint $table): void {
            $table->string('manufacturer')->nullable();
            $table->string('vin')->nullable()->unique();
            $table->foreignId('replaced_by_bus_id')->nullable()->constrained('buses')->nullOnDelete();
            $table->timestampTz('retired_at')->nullable();
        });

        Schema::table('seats', function (Blueprint $table): void {
            $table->string('deck')->default('lower');
            $table->boolean('active')->default(true);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('identity_number')->nullable();
            $table->json('identity_documents')->nullable();
            $table->boolean('manifest_access')->default(false);
            $table->boolean('ticket_scanning_access')->default(false);
            $table->decimal('rating', 3, 2)->nullable();
        });

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->foreignId('assigned_technician_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('parts_used')->nullable();
            $table->unsignedInteger('downtime_minutes')->nullable();
            $table->string('condition_rating')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('next_service_odometer_km')->nullable();
            $table->date('next_service_on')->nullable()->index();
        });

        Schema::create('bus_operational_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('company_branches')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('reference')->nullable();
            $table->dateTimeTz('occurred_at');
            $table->unsignedBigInteger('odometer_km')->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('status')->default('recorded');
            $table->text('notes')->nullable();
            $table->json('details')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'type', 'occurred_at']);
            $table->index(['bus_id', 'type', 'occurred_at']);
        });

        Schema::create('employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('reference')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->string('file_path');
            $table->string('status')->default('pending');
            $table->timestampsTz();
            $table->index(['company_id', 'document_type']);
        });

        Schema::create('staff_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->dateTimeTz('occurred_at');
            $table->decimal('rating', 3, 2)->nullable();
            $table->string('status')->default('open');
            $table->text('notes')->nullable();
            $table->json('details')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'type', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_reports');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('bus_operational_records');
        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_technician_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['parts_used', 'downtime_minutes', 'condition_rating', 'approved_at', 'next_service_odometer_km', 'next_service_on']);
        });
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['phone', 'email', 'identity_number', 'identity_documents', 'manifest_access', 'ticket_scanning_access', 'rating']));
        Schema::table('employees', fn (Blueprint $table) => $table->dropForeign(['branch_id']));
        Schema::table('seats', fn (Blueprint $table) => $table->dropColumn(['deck', 'active']));
        Schema::table('buses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaced_by_bus_id');
            $table->dropUnique(['vin']);
            $table->dropColumn(['manufacturer', 'vin', 'retired_at']);
        });
        Schema::table('buses', fn (Blueprint $table) => $table->dropForeign(['current_branch_id']));
    }
};
