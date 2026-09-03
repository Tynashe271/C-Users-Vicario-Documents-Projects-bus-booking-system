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
        Schema::table('wallets', function (Blueprint $table): void {
            $table->string('wallet_type')->nullable();
            $table->decimal('balance', 16, 2)->default(0);
            $table->decimal('available_balance', 16, 2)->default(0);
            $table->decimal('held_balance', 16, 2)->default(0);
            $table->timestampTz('last_transaction_at')->nullable();
            $table->index(['company_id', 'wallet_type', 'currency']);
        });
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_type')->nullable();
            $table->string('direction')->nullable();
            $table->decimal('balance_after', 16, 2)->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestampTz('occurred_at')->nullable()->index();
        });
        Schema::table('commissions', function (Blueprint $table): void {
            $table->foreignId('booking_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->decimal('gross_amount', 16, 2)->nullable();
            $table->decimal('platform_amount', 16, 2)->nullable();
            $table->decimal('agent_amount', 16, 2)->default(0);
            $table->decimal('operator_amount', 16, 2)->nullable();
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->timestampTz('available_at')->nullable()->index();
            $table->timestampTz('settled_at')->nullable();
        });
        Schema::table('settlements', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('gross_amount', 16, 2)->default(0);
            $table->decimal('platform_fees', 16, 2)->default(0);
            $table->decimal('agent_fees', 16, 2)->default(0);
            $table->decimal('net_amount', 16, 2)->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
        });
        Schema::table('settlement_items', function (Blueprint $table): void {
            $table->foreignId('settlement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('commission_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('gross_amount', 16, 2)->nullable();
            $table->decimal('fee_amount', 16, 2)->nullable();
            $table->decimal('net_amount', 16, 2)->nullable();
        });
        Schema::table('reconciliations', function (Blueprint $table): void {
            $table->string('provider')->nullable();
            $table->date('reconciliation_date')->nullable();
            $table->decimal('expected_amount', 16, 2)->nullable();
            $table->decimal('reported_amount', 16, 2)->nullable();
            $table->decimal('difference_amount', 16, 2)->nullable();
            $table->unsignedInteger('expected_transactions')->default(0);
            $table->unsignedInteger('reported_transactions')->default(0);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->unique(['company_id', 'provider', 'reconciliation_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reconciliations', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'provider', 'reconciliation_date']);
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['provider', 'reconciliation_date', 'expected_amount', 'reported_amount', 'difference_amount', 'expected_transactions', 'reported_transactions', 'resolved_at']);
        });
        Schema::table('settlement_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('booking_id');
            $table->dropConstrainedForeignId('commission_id');
            $table->dropConstrainedForeignId('settlement_id');
            $table->dropColumn(['gross_amount', 'fee_amount', 'net_amount']);
        });
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'period_start', 'period_end', 'gross_amount', 'platform_fees', 'agent_fees', 'net_amount', 'payment_reference', 'approved_at', 'paid_at']);
        });
        Schema::table('commissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('booking_id');
            $table->dropColumn(['gross_amount', 'platform_amount', 'agent_amount', 'operator_amount', 'tax_amount', 'available_at', 'settled_at']);
        });
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropConstrainedForeignId('booking_id');
            $table->dropConstrainedForeignId('wallet_id');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['transaction_type', 'direction', 'balance_after', 'idempotency_key', 'occurred_at']);
        });
        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'wallet_type', 'currency']);
            $table->dropColumn(['wallet_type', 'balance', 'available_balance', 'held_balance', 'last_transaction_at']);
        });
    }
};
