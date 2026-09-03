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
        // Canned replies support staff can reuse when answering a support case (name = template
        // title, data->body = the reply text, data->category = which case category it's meant
        // for). Shaped like every other generic platform-module table (see
        // create_platform_module_tables) so it works through the existing
        // PlatformResourceController without a bespoke controller.
        Schema::create('response_templates', function (Blueprint $table): void {
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('response_templates');
    }
};
