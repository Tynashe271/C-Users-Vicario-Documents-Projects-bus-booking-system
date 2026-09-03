<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\FareRule;
use App\Models\Trip;
use Illuminate\Validation\ValidationException;

class PricingService
{
    /**
     * @param  list<array<string, mixed>>  $passengers
     * @param  list<string>  $services
     * @return array{subtotal:float, discount:float, taxes:float, fees:float, platform_fee:float, total:float, passenger_fares:list<float>, services:list<array{code:string, price:float}>, coupon:?string}
     */
    public function quote(Trip $trip, array $passengers, array $services = [], ?string $couponCode = null, bool $redeemCoupon = false, ?int $userId = null): array
    {
        $rules = FareRule::where('company_id', $trip->company_id)->where('status', 'active')->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $trip->departs_at))->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $trip->departs_at))->orderBy('amount')->get();
        $multipliers = ['adult' => 1.0, 'child' => 0.5, 'infant' => 0.1, 'student' => 0.8, 'senior' => 0.8];
        $seats = $trip->bus()->firstOrFail()->seats()->whereIn('id', collect($passengers)->pluck('seat_id')->filter())->get()->keyBy('id');
        $seatClassCharges = data_get($trip->company()->first()?->settings, 'seat_class_charges', []);
        $passengerFares = collect($passengers)->map(function (array $passenger) use ($trip, $rules, $multipliers, $seats, $seatClassCharges): float {
            $fare = (float) $trip->base_fare * ($multipliers[$passenger['type']] ?? 1.0);
            if (isset($passenger['seat_id'])) {
                $seat = $seats->get($passenger['seat_id']);
                if (! $seat) {
                    throw ValidationException::withMessages(['passengers' => 'One or more quoted seats do not belong to this trip bus.']);
                }
                $fare += (float) ($seatClassCharges[$seat->type] ?? 0);
            }
            foreach ($rules as $rule) {
                $conditions = $rule->data ?? [];
                if (($conditions['route_id'] ?? $trip->route_id) !== $trip->route_id || ($conditions['passenger_type'] ?? $passenger['type']) !== $passenger['type']) {
                    continue;
                }
                if (! $this->conditionsMatchTrip($conditions, $trip)) {
                    continue;
                }
                $fare = match ($conditions['adjustment_type'] ?? 'percentage') {
                    'fixed' => $fare + (float) $rule->amount,
                    'override' => (float) $rule->amount,
                    default => $fare * (1 + ((float) $rule->amount / 100)),
                };
            }

            return round(max($fare, 0), 2);
        })->values()->all();
        $company = $trip->company()->first();
        $catalog = collect(data_get($company?->settings, 'optional_services', []))->keyBy('code');
        $selectedServices = collect($services)->map(function (string $code) use ($catalog): array {
            $service = $catalog->get($code);
            if (! $service) {
                throw ValidationException::withMessages(['optional_services' => "Unknown optional service: {$code}."]);
            }

            return ['code' => $code, 'price' => round((float) $service['price'], 2)];
        })->values()->all();
        $serviceTotal = round(collect($selectedServices)->sum('price'), 2);
        $subtotal = round(array_sum($passengerFares) + $serviceTotal, 2);
        $discount = $this->couponDiscount($trip, $couponCode, $subtotal, count($passengers), $redeemCoupon, $userId);
        $taxRate = (float) data_get($company?->settings, 'tax_rate', 0);
        $serviceFee = (float) data_get($company?->settings, 'booking_service_fee', 0);
        $terminalCharges = (float) data_get($company?->settings, 'terminal_charge', 0);
        $flatCommissionRate = (float) data_get($company?->settings, 'commission_rate', 0);
        $platformRate = $trip->route ? $trip->route->commissionRate($subtotal - $discount, $flatCommissionRate) : $flatCommissionRate;
        $taxes = round(($subtotal - $discount) * $taxRate / 100, 2);
        $platformFee = round(($subtotal - $discount) * $platformRate / 100, 2);
        $total = round($subtotal - $discount + $taxes + $terminalCharges + $serviceFee + $platformFee, 2);

        return ['base_fare' => round((float) $trip->base_fare, 2), 'subtotal' => $subtotal, 'discount' => $discount, 'taxes' => $taxes, 'terminal_charges' => $terminalCharges, 'fees' => $serviceFee + $terminalCharges, 'platform_fee' => $platformFee, 'total' => $total, 'passenger_fares' => $passengerFares, 'services' => $selectedServices, 'coupon' => $couponCode];
    }

    /**
     * Beyond the always-checked route/passenger-type match, a fare rule's `data` may further
     * scope it to weekend/peak-period departures (`days_of_week`, `hour_range`) or to bookings
     * made a minimum number of days ahead of departure (`min_days_before_departure`).
     *
     * @param  array<string, mixed>  $conditions
     */
    private function conditionsMatchTrip(array $conditions, Trip $trip): bool
    {
        if (isset($conditions['days_of_week']) && ! in_array($trip->departs_at->isoWeekday(), $conditions['days_of_week'], true)) {
            return false;
        }
        if (isset($conditions['hour_range']['from'], $conditions['hour_range']['to'])) {
            $departureTime = $trip->departs_at->format('H:i');
            if ($departureTime < $conditions['hour_range']['from'] || $departureTime > $conditions['hour_range']['to']) {
                return false;
            }
        }
        if (isset($conditions['min_days_before_departure']) && now()->diffInDays($trip->departs_at, false) < (float) $conditions['min_days_before_departure']) {
            return false;
        }

        return true;
    }

    private function couponDiscount(Trip $trip, ?string $code, float $subtotal, int $passengerCount, bool $redeem, ?int $userId): float
    {
        if (blank($code)) {
            return 0;
        }
        $query = Coupon::where('code', $code)->where('status', 'active')->where(fn ($builder) => $builder->whereNull('company_id')->orWhere('company_id', $trip->company_id))->where(fn ($builder) => $builder->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($builder) => $builder->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
        $coupon = $redeem ? $query->lockForUpdate()->first() : $query->first();
        if (! $coupon) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is invalid or expired.']);
        }
        if ($coupon->user_id !== null && $coupon->user_id !== $userId) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is not available to this passenger.']);
        }
        $settings = $coupon->data ?? [];
        if (isset($settings['route_id']) && (int) $settings['route_id'] !== $trip->route_id) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon does not apply to the selected route.']);
        }
        if ($passengerCount < (int) ($settings['minimum_passengers'] ?? 1) || $subtotal < (float) ($settings['minimum_spend'] ?? 0)) {
            throw ValidationException::withMessages(['coupon_code' => 'The booking does not meet this coupon’s requirements.']);
        }
        $used = (int) ($settings['used_count'] ?? 0);
        if (isset($settings['usage_limit']) && $used >= (int) $settings['usage_limit']) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon has reached its usage limit.']);
        }
        $discount = ($settings['discount_type'] ?? 'percentage') === 'fixed' ? (float) $coupon->amount : $subtotal * (float) $coupon->amount / 100;
        $discount = round(min($discount, (float) ($settings['maximum_discount'] ?? $discount), $subtotal), 2);
        if ($redeem) {
            $settings['used_count'] = $used + 1;
            $coupon->update(['data' => $settings]);
        }

        return $discount;
    }

    public function restoreCoupon(Booking $booking): void
    {
        $code = data_get($booking->fare_breakdown, 'coupon');
        if (blank($code)) {
            return;
        }

        $coupon = Coupon::where('code', $code)->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $booking->company_id))->lockForUpdate()->first();
        if (! $coupon) {
            return;
        }

        $settings = $coupon->data ?? [];
        $settings['used_count'] = max(0, (int) ($settings['used_count'] ?? 0) - 1);
        $coupon->update(['data' => $settings]);
    }
}
