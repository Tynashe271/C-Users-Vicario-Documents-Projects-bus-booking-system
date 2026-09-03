<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\CorporateBookingRequest;
use App\Models\CorporateInvoice;
use App\Models\CorporateMember;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\BookingService;
use App\Services\FinanceService;
use App\Services\PassengerJourneyNotificationService;
use App\Services\PricingService;
use App\Services\TicketDeliveryService;
use App\Services\TripOccupancyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CorporateController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', Rule::exists('companies', 'id')],
            'name' => ['required', 'string', 'max:150'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'industry' => ['nullable', 'string', 'max:100'],
            'billing_email' => ['required', 'email', 'max:255'],
            'billing_phone' => ['required', 'string', 'max:30'],
            'billing_address' => ['nullable', 'array'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);
        abort_if(CorporateAccount::where('company_id', $validated['company_id'])->where('user_id', $request->user()->id)->exists(), 409, 'You already registered a corporate account with this operator.');
        $account = CorporateAccount::create([...$validated, 'currency' => strtoupper($validated['currency'] ?? 'USD'), 'user_id' => $request->user()->id, 'code' => 'CORP-'.strtoupper(Str::random(8)), 'status' => 'pending', 'primary_contact' => ['name' => $request->user()->name, 'email' => $request->user()->email, 'phone' => $request->user()->phone]]);

        return response()->json($account, 201);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(CorporateAccount::where('company_id', $this->staffCompanyId($request))->latest()->paginate(25));
    }

    public function show(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeView($request, $account);

        return response()->json($account->load(['costCentres', 'admin:id,name,email'])->loadCount(['members', 'bookingRequests']));
    }

    public function verify(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeStaff($request, $account);
        abort_unless($account->status === 'pending', 409, 'Only a pending application can be verified.');
        $validated = $request->validate(['decision' => ['required', Rule::in(['verified', 'rejected'])], 'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000']]);
        $account->update(['status' => $validated['decision'], 'verified_at' => $validated['decision'] === 'verified' ? now() : null, 'data' => [...($account->data ?? []), 'decision_reason' => $validated['reason'] ?? null]]);

        return response()->json($account->refresh());
    }

    public function suspend(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeStaff($request, $account);
        abort_unless($account->status === 'verified', 409, 'Only a verified corporate account can be suspended.');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $account->update(['status' => 'suspended', 'suspended_at' => now(), 'data' => [...($account->data ?? []), 'suspension_reason' => $validated['reason']]]);

        return response()->json($account->refresh());
    }

    public function activate(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeStaff($request, $account);
        abort_unless($account->status === 'suspended', 409, 'Only a suspended corporate account can be reactivated.');
        $account->update(['status' => 'verified', 'suspended_at' => null]);

        return response()->json($account->refresh());
    }

    public function updateCreditLimit(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeStaff($request, $account);
        $validated = $request->validate(['credit_limit' => ['nullable', 'numeric', 'min:0'], 'negotiated_discount_percent' => ['nullable', 'numeric', 'between:0,100']]);
        $account->update($validated);

        return response()->json($account->refresh());
    }

    public function indexMembers(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeView($request, $account);

        return response()->json($account->members()->with('costCentre:id,name')->orderBy('name')->paginate(50));
    }

    public function storeMember(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeAdmin($request, $account);
        abort_unless($account->status === 'verified', 409, 'The corporate account must be verified before adding members.');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'employee_number' => ['nullable', 'string', 'max:100'], 'member_type' => ['required', Rule::in(['employee', 'student'])],
            'cost_centre_id' => ['nullable', Rule::exists('cost_centres', 'id')->where('corporate_account_id', $account->id)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $member = $account->members()->create([...$validated, 'company_id' => $account->company_id, 'code' => 'MEM-'.strtoupper(Str::random(8)), 'status' => 'active']);

        return response()->json($member, 201);
    }

    public function updateMember(Request $request, CorporateAccount $account, CorporateMember $member): JsonResponse
    {
        $this->authorizeAdmin($request, $account);
        abort_unless($member->corporate_account_id === $account->id, 404);
        $validated = $request->validate(['status' => ['sometimes', Rule::in(['active', 'inactive'])], 'cost_centre_id' => ['sometimes', 'nullable', Rule::exists('cost_centres', 'id')->where('corporate_account_id', $account->id)]]);
        $member->update($validated);

        return response()->json($member->refresh());
    }

    public function indexCostCentres(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeView($request, $account);

        return response()->json($account->costCentres()->orderBy('name')->get());
    }

    public function storeCostCentre(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeAdmin($request, $account);
        $validated = $request->validate(['name' => ['required', 'string', 'max:150'], 'budget_limit' => ['nullable', 'numeric', 'min:0']]);
        $centre = $account->costCentres()->create([...$validated, 'company_id' => $account->company_id, 'code' => 'CC-'.strtoupper(Str::random(6)), 'status' => 'active']);

        return response()->json($centre, 201);
    }

    public function indexBookingRequests(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeView($request, $account);

        return response()->json($account->bookingRequests()->with(['trip.route.origin', 'trip.route.destination', 'costCentre:id,name'])->latest()->paginate(25));
    }

    public function storeBookingRequest(Request $request, CorporateAccount $account, PricingService $pricing): JsonResponse
    {
        $this->authorizeAdmin($request, $account);
        abort_unless($account->status === 'verified', 409, 'The corporate account must be verified before booking.');
        $validated = $request->validate([
            'trip_id' => ['required', Rule::exists('trips', 'id')->where('company_id', $account->company_id)],
            'cost_centre_id' => ['nullable', Rule::exists('cost_centres', 'id')->where('corporate_account_id', $account->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'passengers' => ['required', 'array', 'min:1', 'max:50'],
            'passengers.*.seat_id' => ['required', 'integer', 'distinct'], 'passengers.*.full_name' => ['required', 'string', 'max:150'],
            'passengers.*.phone' => ['required', 'string', 'max:30'], 'passengers.*.email' => ['required', 'email', 'max:254'],
            'passengers.*.type' => ['required', 'in:adult,child,infant,student,senior'],
        ]);
        $trip = Trip::findOrFail($validated['trip_id']);
        $quote = $pricing->quote($trip, $validated['passengers'], [], null, false, $account->user_id);
        $discount = (float) ($account->negotiated_discount_percent ?? 0);
        $estimatedTotal = round((float) $quote['total'] * (1 - $discount / 100), 2);
        abort_if($account->credit_limit !== null && (float) $account->outstanding_balance + $estimatedTotal > (float) $account->credit_limit, 422, 'This request would exceed the corporate account\'s available credit.');

        $bookingRequest = CorporateBookingRequest::create(['company_id' => $account->company_id, 'corporate_account_id' => $account->id, 'cost_centre_id' => $validated['cost_centre_id'] ?? null, 'trip_id' => $trip->id, 'requested_by' => $request->user()->id, 'passenger_count' => count($validated['passengers']), 'passengers' => $validated['passengers'], 'estimated_total' => $estimatedTotal, 'currency' => $trip->currency, 'notes' => $validated['notes'] ?? null, 'status' => 'pending']);

        return response()->json($bookingRequest, 201);
    }

    public function decideBookingRequest(Request $request, CorporateAccount $account, CorporateBookingRequest $bookingRequest, BookingService $bookingService, FinanceService $financeService, TicketDeliveryService $delivery, PassengerJourneyNotificationService $notifications, TripOccupancyService $occupancy): JsonResponse
    {
        $this->authorizeStaff($request, $account);
        abort_unless($bookingRequest->corporate_account_id === $account->id, 404);
        abort_unless($bookingRequest->status === 'pending', 409, 'This request was already decided.');
        $validated = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000']]);

        if ($validated['decision'] === 'rejected') {
            $bookingRequest->update(['status' => 'rejected', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_reason' => $validated['reason'] ?? null]);

            return response()->json($bookingRequest->refresh());
        }

        $trip = $bookingRequest->trip()->firstOrFail();
        $seatIds = collect($bookingRequest->passengers)->pluck('seat_id')->all();
        $result = DB::transaction(function () use ($request, $account, $bookingRequest, $trip, $seatIds, $bookingService, $financeService, $delivery, $notifications, $occupancy): CorporateBookingRequest {
            $account = CorporateAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            // Re-check credit at approval time: the balance may have moved since the request was made.
            abort_if($account->credit_limit !== null && (float) $account->outstanding_balance + (float) $bookingRequest->estimated_total > (float) $account->credit_limit, 422, 'Approving this request would exceed the corporate account\'s available credit.');

            $lock = $bookingService->lockSeats($trip, $seatIds, $bookingRequest->requested_by);
            $booking = $bookingService->create($trip, $lock['token'], ['contact_name' => $account->name, 'contact_email' => $account->billing_email, 'contact_phone' => $account->billing_phone, 'booking_type' => 'corporate', 'source' => 'corporate', 'passengers' => $bookingRequest->passengers], $bookingRequest->requested_by);
            $this->applyNegotiatedDiscount($booking, $account);
            $payment = Payment::create(['booking_id' => $booking->id, 'provider' => 'corporate_credit', 'provider_reference' => 'CORP-REQ-'.$bookingRequest->id, 'idempotency_key' => 'corporate-request:'.$bookingRequest->id, 'amount' => $booking->total, 'currency' => $booking->currency, 'status' => 'paid', 'paid_at' => now()]);
            $bookingService->confirmPaidBooking($booking, $payment, $financeService, $delivery, $notifications, $occupancy);
            $booking->update(['corporate_account_id' => $account->id, 'cost_centre_id' => $bookingRequest->cost_centre_id]);
            $account->update(['outstanding_balance' => round((float) $account->outstanding_balance + (float) $booking->total, 2)]);
            $bookingRequest->update(['status' => 'booked', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'booking_id' => $booking->id]);

            return $bookingRequest->refresh();
        });

        return response()->json($result);
    }

    public function indexInvoices(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeView($request, $account);

        return response()->json($account->invoices()->orderByDesc('period_start')->get());
    }

    public function storeInvoice(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeStaff($request, $account);
        $validated = $request->validate(['period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start']]);
        $bookings = $account->bookings()->whereNull('corporate_invoice_id')->whereIn('status', ['confirmed', 'completed'])
            ->whereBetween('created_at', [$validated['period_start'], Carbon::parse($validated['period_end'])->endOfDay()])->get();
        abort_if($bookings->isEmpty(), 422, 'There are no uninvoiced bookings in this period.');
        $subtotal = round((float) $bookings->sum('total'), 2);
        $invoice = $account->invoices()->create(['company_id' => $account->company_id, 'code' => 'INV-'.$account->id.'-'.now()->format('YmdHis'), 'name' => 'Corporate invoice', 'status' => 'issued', 'currency' => $bookings->first()->currency, 'period_start' => $validated['period_start'], 'period_end' => $validated['period_end'], 'subtotal' => $subtotal, 'tax' => 0, 'total' => $subtotal, 'due_date' => today()->addDays(14), 'issued_at' => now()]);
        Booking::whereIn('id', $bookings->pluck('id'))->update(['corporate_invoice_id' => $invoice->id]);

        return response()->json($invoice->load('bookings:id,reference,total'), 201);
    }

    public function payInvoice(Request $request, CorporateAccount $account, CorporateInvoice $invoice): JsonResponse
    {
        abort_unless($invoice->corporate_account_id === $account->id, 404);
        abort_unless($invoice->status === 'issued', 409, 'Only an issued invoice can be paid.');
        $validated = $request->validate(['method' => ['required', Rule::in(['wallet', 'manual'])], 'reference' => ['required_if:method,manual', 'nullable', 'string', 'max:150']]);

        if ($validated['method'] === 'wallet') {
            $this->authorizeAdmin($request, $account);
            DB::transaction(function () use ($account, $invoice): void {
                $wallet = Wallet::whereKey($this->walletFor($account)->id)->lockForUpdate()->firstOrFail();
                abort_if((float) $wallet->balance < (float) $invoice->total, 422, 'Insufficient corporate wallet balance.');
                $newBalance = round((float) $wallet->balance - (float) $invoice->total, 2);
                $wallet->update(['balance' => $newBalance, 'available_balance' => $newBalance, 'last_transaction_at' => now()]);
                WalletTransaction::create(['company_id' => $account->company_id, 'wallet_id' => $wallet->id, 'code' => 'invoice-payment:'.$invoice->id, 'name' => 'Corporate invoice payment', 'status' => 'posted', 'amount' => $invoice->total, 'currency' => $invoice->currency, 'transaction_type' => 'invoice_payment', 'direction' => 'debit', 'balance_after' => $newBalance, 'idempotency_key' => 'corp-invoice-payment:'.$invoice->id, 'occurred_at' => now()]);
                $invoice->update(['status' => 'paid', 'paid_at' => now()]);
                $account->update(['outstanding_balance' => max(0, round((float) $account->outstanding_balance - (float) $invoice->total, 2))]);
            });
        } else {
            $this->authorizeStaff($request, $account);
            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'data' => [...($invoice->data ?? []), 'payment_reference' => $validated['reference']]]);
            $account->update(['outstanding_balance' => max(0, round((float) $account->outstanding_balance - (float) $invoice->total, 2))]);
        }

        return response()->json($invoice->refresh());
    }

    public function wallet(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeView($request, $account);

        return response()->json($this->walletFor($account));
    }

    public function depositWallet(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeAdmin($request, $account);
        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);
        $wallet = DB::transaction(function () use ($account, $validated): Wallet {
            $wallet = Wallet::whereKey($this->walletFor($account)->id)->lockForUpdate()->firstOrFail();
            $newBalance = round((float) $wallet->balance + $validated['amount'], 2);
            $wallet->update(['balance' => $newBalance, 'available_balance' => $newBalance, 'last_transaction_at' => now()]);
            WalletTransaction::create(['company_id' => $account->company_id, 'wallet_id' => $wallet->id, 'code' => 'deposit:'.$wallet->id.':'.now()->format('YmdHisu'), 'name' => 'Corporate wallet deposit', 'status' => 'posted', 'amount' => $validated['amount'], 'currency' => $wallet->currency, 'transaction_type' => 'deposit', 'direction' => 'credit', 'balance_after' => $newBalance, 'idempotency_key' => 'corp-deposit:'.$wallet->id.':'.now()->format('YmdHisu').':'.Str::random(6), 'occurred_at' => now()]);

            return $wallet->refresh();
        });

        return response()->json($wallet, 201);
    }

    public function statement(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeView($request, $account);

        return response()->json(['account_id' => $account->id, 'credit_limit' => $account->credit_limit, 'outstanding_balance' => $account->outstanding_balance, 'available_credit' => $account->availableCredit(), 'wallet_balance' => (float) $this->walletFor($account)->balance, 'invoices' => $account->invoices()->orderByDesc('period_start')->get()]);
    }

    public function report(Request $request, CorporateAccount $account): JsonResponse
    {
        $this->authorizeView($request, $account);
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $bookings = $account->bookings()->whereIn('status', ['confirmed', 'completed'])
            ->when($validated['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->get();

        return response()->json([
            'trips_taken' => $bookings->count(), 'total_spend' => round((float) $bookings->sum('total'), 2),
            'spend_by_cost_centre' => $bookings->groupBy('cost_centre_id')->map(fn ($group) => round((float) $group->sum('total'), 2)),
            'spend_by_requester' => $bookings->groupBy('user_id')->map(fn ($group) => round((float) $group->sum('total'), 2)),
        ]);
    }

    /**
     * PricingService has no notion of a corporate account, so it prices the trip at the operator's
     * normal fares. Apply the account's negotiated rate here, on the real booking, moving the
     * difference into `discount` so subtotal - discount + taxes + fees + platform_fee still equals
     * total. The platform fee is untouched, so the reduction comes out of the operator's own share.
     */
    private function applyNegotiatedDiscount(Booking $booking, CorporateAccount $account): void
    {
        $discountPercent = (float) ($account->negotiated_discount_percent ?? 0);
        if ($discountPercent <= 0) {
            return;
        }
        $originalTotal = (float) $booking->total;
        $discountedTotal = round($originalTotal * (1 - $discountPercent / 100), 2);
        $reduction = round($originalTotal - $discountedTotal, 2);
        $breakdown = $booking->fare_breakdown ?? [];
        $breakdown['discount'] = round((float) ($breakdown['discount'] ?? 0) + $reduction, 2);
        $breakdown['total'] = $discountedTotal;
        $breakdown['corporate_negotiated_discount_percent'] = $discountPercent;
        $booking->update(['discount' => round((float) $booking->discount + $reduction, 2), 'total' => $discountedTotal, 'fare_breakdown' => $breakdown]);
    }

    private function walletFor(CorporateAccount $account): Wallet
    {
        return Wallet::firstOrCreate(['company_id' => $account->company_id, 'code' => 'corporate:'.$account->id], ['name' => $account->name.' wallet', 'wallet_type' => 'corporate', 'status' => 'active', 'currency' => $account->currency ?? 'USD']);
    }

    private function staffCompanyId(Request $request): int
    {
        abort_unless($request->user()->company_id && $request->user()->can('bookings.manage'), 403);

        return $request->user()->company_id;
    }

    private function authorizeStaff(Request $request, CorporateAccount $account): void
    {
        abort_unless($request->user()->company_id === $account->company_id && $request->user()->can('bookings.manage'), 404);
    }

    private function authorizeAdmin(Request $request, CorporateAccount $account): void
    {
        abort_unless($account->user_id === $request->user()->id, 403);
    }

    private function authorizeView(Request $request, CorporateAccount $account): void
    {
        $isAdmin = $account->user_id === $request->user()->id;
        $isStaff = $request->user()->company_id === $account->company_id && $request->user()->can('bookings.manage');
        abort_unless($isAdmin || $isStaff, 404);
    }
}
