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
        Schema::table('notifications', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
            $table->string('event_type')->nullable()->index();
            $table->string('channel')->nullable()->index();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->text('recipient')->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
        });
        Schema::table('support_cases', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
            $table->string('case_number')->nullable()->unique();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parcel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category')->nullable()->index();
            $table->string('priority')->default('normal')->index();
            $table->string('subject')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('first_response_due_at')->nullable();
            $table->timestampTz('resolution_due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedTinyInteger('satisfaction_rating')->nullable();
        });
        Schema::table('support_messages', function (Blueprint $table): void {
            $table->foreignId('support_case_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->boolean('internal')->default(false);
            $table->json('attachments')->nullable();
            $table->timestampTz('seen_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('support_case_id');
            $table->dropColumn(['message', 'internal', 'attachments', 'seen_at']);
        });
        Schema::table('support_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('parcel_id');
            $table->dropConstrainedForeignId('booking_id');
            $table->dropUnique(['public_id']);
            $table->dropUnique(['case_number']);
            $table->dropColumn(['public_id', 'case_number', 'category', 'priority', 'subject', 'description', 'first_response_due_at', 'resolution_due_at', 'resolved_at', 'closed_at', 'satisfaction_rating']);
        });
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'event_type', 'channel', 'subject', 'body', 'recipient', 'scheduled_at', 'sent_at', 'failed_at', 'read_at', 'attempts', 'last_error']);
        });
    }
};
