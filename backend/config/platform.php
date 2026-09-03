<?php

$modules = [
    'profiles', 'company_documents', 'branches', 'employees', 'agents', 'agent_wallets', 'bus_documents',
    'amenities', 'bus_amenities', 'seat_layouts', 'drivers', 'conductors', 'countries', 'provinces', 'cities',
    'route_stops', 'schedules', 'trip_stops', 'trip_staff', 'fares', 'fare_rules', 'booking_seats',
    'payment_attempts', 'boarding_scans', 'cancellations', 'refunds', 'wallets', 'wallet_transactions',
    'commissions', 'settlements', 'coupons', 'loyalty_accounts', 'loyalty_transactions', 'reviews',
    'notifications', 'support_cases', 'parcels', 'parcel_events', 'gps_locations', 'maintenance_records',
    'incidents', 'audit_logs', 'corporate_accounts', 'corporate_members', 'corporate_invoices', 'cost_centres',
    'promotions', 'gift_cards', 'vouchers', 'referrals', 'saved_passengers', 'saved_routes',
    'saved_payment_methods', 'notification_preferences', 'login_devices', 'account_requests',
    'recent_searches', 'waitlists', 'luggage_records', 'luggage_tags', 'lost_properties', 'support_messages',
    'faq_articles', 'agent_deposits', 'agent_commissions', 'agent_withdrawals', 'settlement_items',
    'reconciliations', 'chargebacks', 'fraud_alerts', 'payment_provider_reports', 'vehicle_locations',
    'tracking_links', 'fuel_records', 'inspection_records', 'insurance_records', 'permit_records',
    'staff_assignments', 'working_hours', 'leave_records', 'training_records', 'performance_reports',
    'trip_expenses', 'campaigns', 'campaign_messages', 'featured_listings', 'api_clients', 'security_events',
    'system_settings', 'content_pages', 'policies', 'report_exports', 'analytics_snapshots',
    'offline_sync_batches', 'webhook_events', 'file_uploads', 'emergency_contacts', 'travel_documents',
    'terms_acceptances', 'passenger_preferences', 'trip_comparisons', 'optional_services', 'booking_claims',
    'receipts', 'payment_methods', 'operator_policies', 'pre_trip_checklists',
    'manual_boarding_verifications', 'trip_status_updates', 'incident_attachments', 'agent_statements',
    'review_moderations', 'financial_ledger_entries', 'collection_proofs', 'response_templates',
];

return [
    'modules' => array_fill_keys($modules, true),
    'platform_roles' => ['super_administrator', 'operator_approval_officer', 'platform_finance_officer', 'customer_support_officer', 'marketing_manager', 'security_administrator', 'system_auditor'],
    'company_roles' => ['company_owner', 'company_administrator', 'operations_manager', 'fleet_manager', 'finance_manager', 'branch_manager', 'booking_clerk', 'customer_support_agent', 'driver', 'conductor', 'terminal_officer', 'maintenance_officer'],
];
