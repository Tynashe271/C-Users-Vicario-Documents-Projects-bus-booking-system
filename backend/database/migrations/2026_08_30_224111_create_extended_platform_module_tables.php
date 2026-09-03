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
        foreach ($this->modules() as $module) {
            Schema::create($module, function (Blueprint $table): void {
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
                $table->index(['user_id', 'status']);
                $table->unique(['company_id', 'code']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->modules()) as $module) {
            Schema::dropIfExists($module);
        }
    }

    /** @return list<string> */
    private function modules(): array
    {
        return [
            'terms_acceptances', 'passenger_preferences', 'trip_comparisons', 'optional_services',
            'booking_claims', 'receipts', 'payment_methods', 'operator_policies', 'pre_trip_checklists',
            'manual_boarding_verifications', 'trip_status_updates', 'incident_attachments',
            'agent_statements', 'review_moderations', 'financial_ledger_entries', 'collection_proofs',
        ];
    }
};
