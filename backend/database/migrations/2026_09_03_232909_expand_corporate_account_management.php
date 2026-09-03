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
        // corporate_accounts, corporate_members, and cost_centres already exist as generic
        // platform-module tables (see create_platform_module_tables); promote them with the
        // dedicated relational columns this feature needs, the same way employees/maintenance_records
        // and route_stops were promoted elsewhere in this schema.
        Schema::table('corporate_accounts', function (Blueprint $table): void {
            $table->string('registration_number')->nullable();
            $table->string('industry')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('billing_phone')->nullable();
            $table->json('billing_address')->nullable();
            $table->json('primary_contact')->nullable();
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->decimal('outstanding_balance', 14, 2)->default(0);
            $table->decimal('negotiated_discount_percent', 5, 2)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
        });

        Schema::table('corporate_members', function (Blueprint $table): void {
            $table->foreignId('corporate_account_id')->nullable()->constrained('corporate_accounts')->cascadeOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained('cost_centres')->nullOnDelete();
            $table->string('member_type')->nullable();
            $table->string('employee_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
        });

        Schema::table('cost_centres', function (Blueprint $table): void {
            $table->foreignId('corporate_account_id')->nullable()->constrained('corporate_accounts')->cascadeOnDelete();
            $table->decimal('budget_limit', 14, 2)->nullable();
        });

        Schema::table('corporate_invoices', function (Blueprint $table): void {
            $table->foreignId('corporate_account_id')->nullable()->constrained('corporate_accounts')->cascadeOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('subtotal', 14, 2)->nullable();
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('corporate_account_id')->nullable()->constrained('corporate_accounts')->nullOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained('cost_centres')->nullOnDelete();
            $table->foreignId('corporate_invoice_id')->nullable()->constrained('corporate_invoices')->nullOnDelete();
        });

        Schema::create('corporate_booking_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('corporate_account_id')->constrained('corporate_accounts')->cascadeOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained('cost_centres')->nullOnDelete();
            $table->foreignId('trip_id')->constrained();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('passenger_count');
            $table->json('passengers');
            $table->decimal('estimated_total', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->text('decision_reason')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->index(['corporate_account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corporate_booking_requests');
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corporate_account_id');
            $table->dropConstrainedForeignId('cost_centre_id');
            $table->dropConstrainedForeignId('corporate_invoice_id');
        });
        Schema::table('corporate_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corporate_account_id');
            $table->dropColumn(['period_start', 'period_end', 'subtotal', 'tax', 'total', 'due_date', 'issued_at', 'paid_at']);
        });
        Schema::table('cost_centres', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corporate_account_id');
            $table->dropColumn('budget_limit');
        });
        Schema::table('corporate_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corporate_account_id');
            $table->dropConstrainedForeignId('cost_centre_id');
            $table->dropColumn(['member_type', 'employee_number', 'email', 'phone']);
        });
        Schema::table('corporate_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'registration_number', 'industry', 'billing_email', 'billing_phone', 'billing_address',
                'primary_contact', 'credit_limit', 'outstanding_balance', 'negotiated_discount_percent',
                'verified_at', 'suspended_at',
            ]);
        });
    }
};
