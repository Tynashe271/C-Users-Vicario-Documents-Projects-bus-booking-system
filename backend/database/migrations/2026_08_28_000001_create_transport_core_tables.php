<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('registration_number')->nullable()->unique();
            $t->string('status')->default('pending');
            $t->char('currency', 3)->default('USD');
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $t->string('role')->default('passenger')->after('password');
            $t->string('phone')->nullable()->unique();
        });
        Schema::create('terminals', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('city');
            $t->string('country', 2);
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->timestamps();
        });
        Schema::create('routes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('origin_terminal_id')->constrained('terminals');
            $t->foreignId('destination_terminal_id')->constrained('terminals');
            $t->string('name');
            $t->unsignedInteger('distance_km')->nullable();
            $t->unsignedInteger('duration_minutes');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('buses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('registration_number')->unique();
            $t->string('model');
            $t->string('class')->default('standard');
            $t->unsignedSmallInteger('seat_capacity');
            $t->string('status')->default('available');
            $t->json('amenities')->nullable();
            $t->timestamps();
        });
        Schema::create('seats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $t->string('number');
            $t->string('type')->default('standard');
            $t->boolean('accessible')->default(false);
            $t->timestamps();
            $t->unique(['bus_id', 'number']);
        });
        Schema::create('trips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('route_id')->constrained();
            $t->foreignId('bus_id')->constrained();
            $t->dateTimeTz('departs_at');
            $t->dateTimeTz('arrives_at');
            $t->decimal('base_fare', 12, 2);
            $t->char('currency', 3)->default('USD');
            $t->string('status')->default('draft');
            $t->timestamps();
            $t->index(['departs_at', 'status']);
        });
        Schema::create('seat_locks', function (Blueprint $t) {
            $t->id();
            $t->uuid('token')->index();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->foreignId('seat_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTimeTz('expires_at');
            $t->timestamps();
            $t->unique(['trip_id', 'seat_id']);
        });
        Schema::create('bookings', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('reference')->unique();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('trip_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('contact_name');
            $t->string('contact_email')->nullable();
            $t->string('contact_phone');
            $t->decimal('subtotal', 12, 2);
            $t->decimal('fees', 12, 2)->default(0);
            $t->decimal('total', 12, 2);
            $t->char('currency', 3);
            $t->string('status')->default('pending_payment');
            $t->timestamps();
        });
        Schema::create('booking_passengers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->foreignId('seat_id')->constrained();
            $t->text('full_name');
            $t->string('type')->default('adult');
            $t->text('document_number')->nullable();
            $t->decimal('fare', 12, 2);
            $t->timestamps();
            $t->unique(['booking_id', 'seat_id']);
            $t->unique(['booking_id', 'document_number']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained();
            $t->string('provider');
            $t->string('provider_reference')->nullable();
            $t->string('idempotency_key')->unique();
            $t->decimal('amount', 12, 2);
            $t->char('currency', 3);
            $t->string('status')->default('pending');
            $t->json('provider_payload')->nullable();
            $t->dateTimeTz('paid_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'provider_reference']);
        });
        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('ticket_number')->unique();
            $t->foreignId('booking_passenger_id')->constrained()->cascadeOnDelete();
            $t->string('qr_token')->unique();
            $t->dateTimeTz('checked_in_at')->nullable();
            $t->dateTimeTz('boarded_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('seat_locks');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('seats');
        Schema::dropIfExists('buses');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('terminals');
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('company_id'));
        Schema::dropIfExists('companies');
    }
};
