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
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employee_number')->nullable();
            $table->string('staff_type')->nullable();
            $table->foreignId('branch_id')->nullable();
            $table->date('hired_on')->nullable();
            $table->string('employment_type')->default('full_time');
            $table->string('availability_status')->default('available');
            $table->text('driver_licence_number')->nullable();
            $table->string('driver_licence_class')->nullable();
            $table->date('driver_licence_expires_on')->nullable()->index();
            $table->text('emergency_contact')->nullable();
            $table->unique(['company_id', 'employee_number']);
        });
        Schema::table('staff_assignments', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('duty_role')->nullable();
            $table->timestampTz('assigned_from')->nullable();
            $table->timestampTz('assigned_until')->nullable();
            $table->timestampTz('checked_in_at')->nullable();
        });
        Schema::table('working_hours', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('clocked_in_at')->nullable();
            $table->timestampTz('clocked_out_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
        });
        Schema::table('leave_records', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->string('leave_type')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
        });
        Schema::table('training_records', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->string('course_name')->nullable();
            $table->string('provider')->nullable();
            $table->date('completed_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->string('certificate_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('training_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['course_name', 'provider', 'completed_on', 'expires_on', 'certificate_path']);
        });
        Schema::table('leave_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['leave_type', 'starts_on', 'ends_on', 'approved_at']);
        });
        Schema::table('working_hours', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trip_id');
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['clocked_in_at', 'clocked_out_at', 'break_minutes']);
        });
        Schema::table('staff_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trip_id');
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['duty_role', 'assigned_from', 'assigned_until', 'checked_in_at']);
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'employee_number']);
            $table->dropColumn('branch_id');
            $table->dropColumn(['employee_number', 'staff_type', 'hired_on', 'employment_type', 'availability_status', 'driver_licence_number', 'driver_licence_class', 'driver_licence_expires_on', 'emergency_contact']);
        });
    }
};
