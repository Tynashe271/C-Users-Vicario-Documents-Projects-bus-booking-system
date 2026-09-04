<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['webhook_subscriptions', 'webhook_deliveries', 'api_usage_records'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_records');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
