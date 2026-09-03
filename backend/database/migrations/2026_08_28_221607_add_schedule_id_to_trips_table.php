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
        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('schedule_id')->nullable()->after('route_id')->constrained('schedules')->nullOnDelete();
            $table->unique(['schedule_id', 'departs_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique(['schedule_id', 'departs_at']);
            $table->dropConstrainedForeignId('schedule_id');
        });
    }
};
