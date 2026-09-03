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
        $modules = [
            'profiles', 'company_documents', 'branches', 'employees', 'agents', 'agent_wallets',
            'bus_documents', 'amenities', 'bus_amenities', 'seat_layouts', 'drivers', 'conductors',
            'countries', 'provinces', 'cities', 'route_stops', 'schedules', 'trip_stops', 'trip_staff',
            'fares', 'fare_rules', 'booking_seats', 'payment_attempts', 'boarding_scans', 'cancellations',
            'refunds', 'wallets', 'wallet_transactions', 'commissions', 'settlements', 'coupons',
            'loyalty_accounts', 'loyalty_transactions', 'reviews', 'notifications', 'support_cases',
            'parcels', 'parcel_events', 'gps_locations', 'maintenance_records', 'incidents', 'audit_logs',
            'corporate_accounts', 'corporate_members', 'corporate_invoices', 'cost_centres', 'promotions',
            'gift_cards', 'vouchers', 'referrals', 'saved_passengers', 'saved_routes', 'saved_payment_methods',
            'notification_preferences', 'login_devices', 'account_requests', 'recent_searches', 'waitlists',
            'luggage_records', 'luggage_tags', 'lost_properties', 'support_messages', 'faq_articles',
            'agent_deposits', 'agent_commissions', 'agent_withdrawals', 'settlement_items', 'reconciliations',
            'chargebacks', 'fraud_alerts', 'payment_provider_reports', 'vehicle_locations', 'tracking_links',
            'fuel_records', 'inspection_records', 'insurance_records', 'permit_records', 'staff_assignments',
            'working_hours', 'leave_records', 'training_records', 'performance_reports', 'trip_expenses',
            'campaigns', 'campaign_messages', 'featured_listings', 'api_clients', 'security_events',
            'system_settings', 'content_pages', 'policies', 'report_exports', 'analytics_snapshots',
            'offline_sync_batches', 'webhook_events', 'file_uploads', 'emergency_contacts', 'travel_documents',
        ];

        foreach ($modules as $module) {
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
        $modules = array_reverse(array_keys(config('platform.modules')));

        foreach ($modules as $module) {
            Schema::dropIfExists($module);
        }
    }
};
