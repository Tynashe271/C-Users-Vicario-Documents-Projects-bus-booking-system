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
        Schema::table('tickets', function (Blueprint $table): void {
            $table->timestampTz('absent_at')->nullable()->after('boarded_at');
            $table->foreignId('checked_in_by')->nullable()->after('absent_at')->constrained('users')->nullOnDelete();
            $table->foreignId('boarded_by')->nullable()->after('checked_in_by')->constrained('users')->nullOnDelete();
            $table->foreignId('marked_absent_by')->nullable()->after('boarded_by')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('marked_absent_by');
            $table->dropConstrainedForeignId('boarded_by');
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn('absent_at');
        });
    }
};
