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
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reported_at')->nullable();
            $table->text('report_reason')->nullable();
            $table->text('company_response')->nullable();
            $table->timestampTz('company_responded_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('moderated_at')->nullable();
            $table->text('moderation_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reported_by');
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['reported_at', 'report_reason', 'company_response', 'company_responded_at', 'moderated_at', 'moderation_reason']);
        });
    }
};
