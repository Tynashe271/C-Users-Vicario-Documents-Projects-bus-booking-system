# Bus Booking Laravel Backend

Implemented foundation: companies, buses/seats, terminals/routes, trips, search, temporary seat holds, transactional bookings, encrypted passenger identity fields, idempotent payment callbacks, and ticket issuance.

## Setup

```powershell
Set-Location backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Run the complete platform stack from the repository root with `docker compose up -d`.

Set a long random `PAYMENT_WEBHOOK_SECRET`. Production must use HTTPS and a certified provider; card data is never stored.

## API (`/api`)

- `GET /trips?origin_terminal_id=1&destination_terminal_id=2&date=2026-09-01`
- `GET /trips/{trip}`
- `POST /trips/{trip}/seat-locks` with `seat_ids`
- `POST /trips/{trip}/bookings` with `lock_token`, contact fields, and passengers
- `GET /bookings/{booking}` (authenticated owner/operator)
- `POST /payments/webhooks/{provider}`

Webhooks use `X-Signature: HMAC-SHA256(raw_body, PAYMENT_WEBHOOK_SECRET)`. A complete successful payment confirms the booking and issues one ticket per passenger.

The database is the double-booking correctness boundary through unique constraints, transactions, and row locks. All future operator endpoints must scope private records to the authenticated user's `company_id`.

Next phases: Sanctum authentication and granular RBAC, operator CRUD policies, payment adapters, QR/PDF rendering, boarding, notifications, settlements, GPS, parcels, GraphQL, and analytics.
