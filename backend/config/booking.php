<?php

return ['seat_lock_minutes' => (int) env('SEAT_LOCK_MINUTES', 10), 'terms_version' => env('BOOKING_TERMS_VERSION', '2026-08-31'), 'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET')];
