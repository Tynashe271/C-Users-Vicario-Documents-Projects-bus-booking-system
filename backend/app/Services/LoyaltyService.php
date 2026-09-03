<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\PlatformResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyService
{
    public const POINTS_PER_DISCOUNT_UNIT = 100;

    public function account(User $user): LoyaltyAccount
    {
        $account = LoyaltyAccount::firstOrCreate(
            ['user_id' => $user->id],
            ['code' => 'LOYALTY-'.$user->id, 'name' => $user->name, 'status' => 'active', 'referral_code' => 'REFER-'.str()->upper(str()->random(8))],
        );
        if (! $account->referral_code) {
            $account->update(['referral_code' => 'REFER-'.str()->upper(str()->random(8))]);
        }
        $this->awardEligibleTrips($user);
        $this->awardBirthday($user, $account);

        return $account->refresh();
    }

    public function awardEligibleTrips(User $user): void
    {
        Booking::query()->whereBelongsTo($user)->whereIn('status', ['confirmed', 'completed'])
            ->whereHas('trip', fn ($query) => $query->where('arrives_at', '<=', now()))
            ->each(fn (Booking $booking) => $this->awardTrip($booking));
    }

    public function awardTrip(Booking $booking): void
    {
        if (! $booking->user_id || ! in_array($booking->status, ['confirmed', 'completed'], true) || $booking->trip()->where('arrives_at', '<=', now())->doesntExist()) {
            return;
        }

        $points = max(1, (int) floor((float) $booking->total));
        $this->credit($booking->user, $points, 'trip', (string) $booking->id, 'Completed trip', $booking);
    }

    public function credit(User $user, int $points, string $type, string $reference, string $name, ?Booking $booking = null): LoyaltyAccount
    {
        return DB::transaction(function () use ($user, $points, $type, $reference, $name, $booking): LoyaltyAccount {
            $account = LoyaltyAccount::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? LoyaltyAccount::create(['user_id' => $user->id, 'code' => 'LOYALTY-'.$user->id, 'name' => $user->name, 'status' => 'active']);
            $transaction = LoyaltyTransaction::firstOrCreate(
                ['transaction_type' => $type, 'reference' => $reference],
                ['user_id' => $user->id, 'loyalty_account_id' => $account->id, 'booking_id' => $booking?->id, 'code' => $type.':'.$reference, 'name' => $name, 'status' => 'completed', 'points' => $points],
            );
            if ($transaction->wasRecentlyCreated) {
                $account->increment('points_balance', $points);
                $account->increment('lifetime_points', $points);
                $account->update(['membership_level' => $this->level((int) $account->lifetime_points)]);
            }

            return $account->refresh();
        });
    }

    public function redeem(User $user, int $points): PlatformResource
    {
        if ($points % self::POINTS_PER_DISCOUNT_UNIT !== 0) {
            throw ValidationException::withMessages(['points' => 'Points must be redeemed in multiples of 100.']);
        }

        return DB::transaction(function () use ($user, $points): PlatformResource {
            $account = LoyaltyAccount::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $account || $account->points_balance < $points) {
                throw ValidationException::withMessages(['points' => 'You do not have enough loyalty points.']);
            }
            $code = 'LOYALTY-'.str()->upper(str()->random(8));
            $coupon = (new PlatformResource)->useModule('coupons');
            $coupon->fill(['user_id' => $user->id, 'code' => $code, 'name' => 'Loyalty reward', 'status' => 'active', 'amount' => $points / self::POINTS_PER_DISCOUNT_UNIT, 'data' => ['discount_type' => 'fixed', 'usage_limit' => 1, 'used_count' => 0, 'source' => 'loyalty']])->save();
            $account->decrement('points_balance', $points);
            LoyaltyTransaction::create(['user_id' => $user->id, 'loyalty_account_id' => $account->id, 'code' => 'redemption:'.$coupon->id, 'name' => 'Points exchanged for discount', 'status' => 'completed', 'points' => -$points, 'transaction_type' => 'redemption', 'reference' => (string) $coupon->id]);

            return $coupon;
        });
    }

    public function claimReward(User $user, string $type, string $code): LoyaltyAccount
    {
        if ($type === 'referral') {
            $referrer = LoyaltyAccount::where('referral_code', str($code)->upper())->where('user_id', '!=', $user->id)->first();
            if ($referrer) {
                $account = $this->credit($user, 100, 'referral', $referrer->id.':'.$user->id, 'Referral welcome reward');
                $this->credit($referrer->user, 100, 'referral_bonus', $referrer->id.':'.$user->id, 'Successful referral reward');

                return $account;
            }
        }
        $module = match ($type) {
            'referral' => 'referrals',
            'voucher' => 'vouchers',
            default => 'promotions',
        };
        $reward = (new PlatformResource)->useModule($module)->newQuery()->where('code', $code)->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->first();
        if (! $reward) {
            throw ValidationException::withMessages(['code' => 'This reward code is invalid or expired.']);
        }

        return $this->credit($user, max(1, (int) $reward->amount), $type, $reward->id.':'.$user->id, ucfirst($type).' reward');
    }

    private function awardBirthday(User $user, LoyaltyAccount $account): void
    {
        if (! $user->date_of_birth || $user->date_of_birth->format('m-d') !== today()->format('m-d') || $account->birthday_rewarded_on?->year === today()->year) {
            return;
        }
        $this->credit($user, 100, 'birthday', $user->id.':'.today()->year, 'Birthday reward');
        $account->update(['birthday_rewarded_on' => today()]);
    }

    private function level(int $lifetimePoints): string
    {
        return match (true) {
            $lifetimePoints >= 5000 => 'Platinum',
            $lifetimePoints >= 1500 => 'Gold',
            $lifetimePoints >= 500 => 'Silver',
            default => 'Bronze',
        };
    }
}
