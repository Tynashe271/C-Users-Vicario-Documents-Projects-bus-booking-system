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
        Schema::table('users', function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable();
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->json('social_links')->nullable();
            $table->json('rescheduling_policy')->nullable();
            $table->json('luggage_policy')->nullable();
            $table->json('boarding_policy')->nullable();
            $table->json('notification_templates')->nullable();
            $table->json('ticket_design')->nullable();
            $table->json('settlement_information')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
        });

        Schema::table('company_branches', function (Blueprint $table): void {
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            $table->string('referral_code')->nullable()->unique();
            $table->date('birthday_rewarded_on')->nullable();
            $table->boolean('expiry_alerts_enabled')->default(true);
        });

        Schema::table('wallets', function (Blueprint $table): void {
            $table->boolean('is_frozen')->default(false);
            $table->string('security_pin')->nullable();
            $table->decimal('daily_spend_limit', 16, 2)->nullable();
        });

        Schema::create('company_application_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('comment');
            $table->boolean('requires_response')->default(false);
            $table->timestampsTz();
            $table->index(['company_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_application_comments');
        Schema::table('wallets', fn (Blueprint $table) => $table->dropColumn(['is_frozen', 'security_pin', 'daily_spend_limit']));
        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'birthday_rewarded_on', 'expiry_alerts_enabled']);
        });
        Schema::table('company_branches', fn (Blueprint $table) => $table->dropConstrainedForeignId('manager_id'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn([
            'description', 'social_links', 'rescheduling_policy', 'luggage_policy', 'boarding_policy',
            'notification_templates', 'ticket_design', 'settlement_information', 'submitted_at',
            'verified_at', 'suspended_at', 'closed_at',
        ]));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('date_of_birth'));
    }
};
