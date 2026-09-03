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
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('trading_name')->nullable();
            $table->string('tax_number')->nullable();
            $table->json('registration_information')->nullable();
            $table->json('business_address')->nullable();
            $table->json('contact_people')->nullable();
            $table->text('bank_details')->nullable();
            $table->string('logo_path')->nullable();
            $table->json('support_information')->nullable();
            $table->json('booking_policy')->nullable();
            $table->json('cancellation_policy')->nullable();
        });

        Schema::table('company_branches', function (Blueprint $table): void {
            $table->json('address')->nullable();
            $table->json('operating_hours')->nullable();
        });

        Schema::create('seat_layout_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('class');
            $table->unsignedSmallInteger('rows');
            $table->unsignedSmallInteger('columns');
            $table->json('elements');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::table('buses', function (Blueprint $table): void {
            $table->foreignId('seat_layout_id')->nullable()->constrained('seat_layout_definitions')->nullOnDelete();
        });

        Schema::table('seats', function (Blueprint $table): void {
            $table->unsignedSmallInteger('row')->nullable();
            $table->unsignedSmallInteger('column')->nullable();
            $table->string('position')->nullable();
            $table->string('berth_level')->nullable();
        });

        Schema::table('bus_documents', function (Blueprint $table): void {
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('expiry_warning_sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bus_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['rejection_reason', 'expiry_warning_sent_at']);
        });
        Schema::table('seats', fn (Blueprint $table) => $table->dropColumn(['row', 'column', 'position', 'berth_level']));
        Schema::table('buses', fn (Blueprint $table) => $table->dropConstrainedForeignId('seat_layout_id'));
        Schema::dropIfExists('seat_layout_definitions');
        Schema::table('company_branches', fn (Blueprint $table) => $table->dropColumn(['address', 'operating_hours']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['trading_name', 'tax_number', 'registration_information', 'business_address', 'contact_people', 'bank_details', 'logo_path', 'support_information', 'booking_policy', 'cancellation_policy']));
    }
};
