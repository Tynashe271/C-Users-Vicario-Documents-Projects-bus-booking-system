<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\SupportCase;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', 'string', 'max:50'], 'priority' => ['nullable', 'string', 'max:20'], 'assigned_to_me' => ['nullable', 'boolean']]);
        $query = SupportCase::query();
        if ($this->isSupportStaff($request)) {
            if (! $this->isPlatformStaff($request)) {
                $query->where('company_id', $request->user()->company_id);
            }
        } else {
            $query->where('user_id', $request->user()->id);
        }
        $cases = $query->when($validated['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status))->when($validated['priority'] ?? null, fn (Builder $builder, string $priority) => $builder->where('priority', $priority))->when($validated['assigned_to_me'] ?? false, fn (Builder $builder) => $builder->where('assigned_to', $request->user()->id))->withCount('messages')->latest()->paginate(25);

        return response()->json($cases);
    }

    public function store(Request $request, NotificationService $notifications): JsonResponse
    {
        $validated = $request->validate(['booking_id' => ['nullable', 'integer', 'exists:bookings,id'], 'parcel_id' => ['nullable', 'integer', 'exists:parcels,id'], 'category' => ['required', Rule::in(['booking', 'payment', 'refund', 'boarding', 'luggage', 'parcel', 'account', 'complaint', 'lost_item', 'other'])], 'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])], 'subject' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:5000'], 'attachments' => ['nullable', 'array', 'max:5'], 'attachments.*' => ['string', 'max:2048']]);
        $companyId = null;
        if (isset($validated['booking_id'])) {
            $booking = Booking::findOrFail($validated['booking_id']);
            abort_unless($booking->user_id === $request->user()->id || $this->isSupportStaff($request), 404);
            $companyId = $booking->company_id;
        }
        $priority = $validated['priority'] ?? 'normal';
        $responseHours = ['low' => 24, 'normal' => 8, 'high' => 2, 'urgent' => 1][$priority];
        $resolutionHours = ['low' => 120, 'normal' => 72, 'high' => 24, 'urgent' => 8][$priority];
        $case = SupportCase::create([...$validated, 'public_id' => Str::uuid(), 'case_number' => 'SUP-'.strtoupper(Str::random(9)), 'company_id' => $companyId, 'user_id' => $request->user()->id, 'code' => Str::uuid(), 'name' => $validated['subject'], 'status' => 'open', 'priority' => $priority, 'first_response_due_at' => now()->addHours($responseHours), 'resolution_due_at' => now()->addHours($resolutionHours)]);
        $case->messages()->create(['company_id' => $companyId, 'user_id' => $request->user()->id, 'code' => Str::uuid(), 'name' => 'Initial request', 'status' => 'sent', 'message' => $validated['description'], 'attachments' => $validated['attachments'] ?? []]);
        $notifications->send($request->user(), 'support_case_created', 'Support request received', "Your support case {$case->case_number} has been opened.", ['case_id' => $case->id]);

        return response()->json($case->load('messages'), 201);
    }

    public function show(Request $request, SupportCase $case): JsonResponse
    {
        $this->authorizeCase($request, $case);
        $query = $case->messages()->oldest();
        if (! $this->isSupportStaff($request)) {
            $query->where('internal', false);
        }

        return response()->json(['case' => $case, 'messages' => $query->get()]);
    }

    public function reply(Request $request, SupportCase $case, NotificationService $notifications): JsonResponse
    {
        $this->authorizeCase($request, $case);
        abort_if(in_array($case->status, ['closed', 'cancelled'], true), 409, 'This support case is closed.');
        $validated = $request->validate(['message' => ['required', 'string', 'max:5000'], 'internal' => ['nullable', 'boolean'], 'attachments' => ['nullable', 'array', 'max:5'], 'attachments.*' => ['string', 'max:2048']]);
        $isStaff = $this->isSupportStaff($request);
        abort_if(($validated['internal'] ?? false) && ! $isStaff, 403);
        $message = $case->messages()->create(['company_id' => $case->company_id, 'user_id' => $request->user()->id, 'code' => Str::uuid(), 'name' => $isStaff ? 'Support reply' : 'Customer reply', 'status' => 'sent', 'message' => $validated['message'], 'internal' => $validated['internal'] ?? false, 'attachments' => $validated['attachments'] ?? []]);
        if ($isStaff && ! $case->assigned_to) {
            $case->update(['assigned_to' => $request->user()->id]);
        }
        if ($isStaff && $case->status === 'open') {
            $case->update(['status' => 'in_progress', 'starts_at' => now()]);
        }
        if ($isStaff && ! $message->internal) {
            $customer = User::find($case->user_id);
            if ($customer) {
                $notifications->send($customer, 'support_reply', 'New support reply', "There is a new reply on case {$case->case_number}.", ['case_id' => $case->id]);
            }
        }

        return response()->json($message, 201);
    }

    public function updateStatus(Request $request, SupportCase $case, NotificationService $notifications): JsonResponse
    {
        abort_unless($this->isSupportStaff($request), 403);
        $this->authorizeCase($request, $case);
        $validated = $request->validate(['status' => ['required', Rule::in(['in_progress', 'waiting_customer', 'escalated', 'resolved', 'closed'])], 'assigned_to' => ['nullable', 'integer', 'exists:users,id'], 'resolution' => ['nullable', 'string', 'max:5000']]);
        $updates = ['status' => $validated['status']];
        if (array_key_exists('assigned_to', $validated)) {
            $updates['assigned_to'] = $validated['assigned_to'];
        } if ($validated['status'] === 'resolved') {
            $updates['resolved_at'] = now();
        } if ($validated['status'] === 'closed') {
            abort_unless($case->status === 'resolved', 409, 'Resolve the case before closing it.');
            $updates['closed_at'] = now();
        }
        $case->update($updates);
        if (! empty($validated['resolution'])) {
            $case->messages()->create(['company_id' => $case->company_id, 'user_id' => $request->user()->id, 'code' => Str::uuid(), 'name' => 'Resolution', 'status' => 'sent', 'message' => $validated['resolution']]);
        }
        $customer = User::find($case->user_id);
        if ($customer) {
            $notifications->send($customer, 'support_status', 'Support case updated', "Case {$case->case_number} is now ".str($case->status)->replace('_', ' ')->toString().'.', ['case_id' => $case->id, 'status' => $case->status]);
        }

        return response()->json($case->refresh());
    }

    public function rate(Request $request, SupportCase $case): JsonResponse
    {
        abort_unless($case->user_id === $request->user()->id, 404);
        abort_unless(in_array($case->status, ['resolved', 'closed'], true), 409, 'Only resolved cases can be rated.');
        $validated = $request->validate(['rating' => ['required', 'integer', 'between:1,5']]);
        $case->update(['satisfaction_rating' => $validated['rating']]);

        return response()->json($case->refresh());
    }

    private function authorizeCase(Request $request, SupportCase $case): void
    {
        $allowed = $case->user_id === $request->user()->id || ($this->isSupportStaff($request) && ($this->isPlatformStaff($request) || $case->company_id === $request->user()->company_id));
        abort_unless($allowed, 404);
    }

    private function isSupportStaff(Request $request): bool
    {
        return $request->user()->can('support.manage');
    }

    private function isPlatformStaff(Request $request): bool
    {
        return in_array($request->user()->role, config('platform.platform_roles'), true);
    }
}
