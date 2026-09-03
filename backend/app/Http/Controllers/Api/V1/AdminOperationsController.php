<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\PlatformResource;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOperationsController extends Controller
{
    public function __invoke(Request $request, string $resource): JsonResponse
    {
        $permissions = [
            'passengers' => 'security.manage',
            'platform-staff' => 'security.manage',
            'buses' => 'platform.manage',
            'trips' => 'platform.manage',
            'bookings' => 'platform.manage',
            'payments' => 'finance.manage',
            'agents' => 'security.manage',
            'drivers' => 'security.manage',
        ];
        abort_unless(isset($permissions[$resource]), 404);
        abort_unless($request->user()->can($permissions[$resource]) || $request->user()->can('platform.manage'), 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->query($resource);
        $this->applyFilters($query, $resource, $validated['search'] ?? null, $validated['status'] ?? null);

        return response()->json($query->latest('created_at')->latest('id')->paginate($validated['per_page'] ?? 25));
    }

    private function query(string $resource): Builder
    {
        return match ($resource) {
            'passengers' => User::query()->whereIn('role', ['passenger', 'guest_passenger', 'corporate_passenger', 'student_passenger', 'frequent_traveller'])
                ->select(['id', 'name', 'email', 'phone', 'role', 'status', 'created_at']),
            'platform-staff' => User::query()->whereNull('company_id')->whereIn('role', config('platform.platform_roles'))
                ->select(['id', 'name', 'email', 'phone', 'role', 'status', 'created_at']),
            'buses' => Bus::query()->with('company:id,name')->select(['id', 'company_id', 'registration_number', 'model', 'class', 'seat_capacity', 'status', 'created_at']),
            'trips' => Trip::query()->with(['company:id,name', 'route:id,name', 'bus:id,registration_number'])
                ->select(['id', 'company_id', 'route_id', 'bus_id', 'departs_at', 'arrives_at', 'base_fare', 'currency', 'status', 'created_at']),
            'bookings' => Booking::query()->with(['trip:id,departs_at', 'company:id,name'])
                ->select(['id', 'company_id', 'trip_id', 'reference', 'contact_name', 'contact_email', 'contact_phone', 'total', 'currency', 'status', 'created_at']),
            'payments' => Payment::query()->with('booking:id,company_id,reference')->select(['id', 'booking_id', 'provider', 'provider_reference', 'amount', 'currency', 'status', 'paid_at', 'created_at']),
            'agents' => (new PlatformResource)->useModule('agents')->newQuery()->select(['id', 'company_id', 'user_id', 'code', 'name', 'status', 'amount as transaction_limit', 'created_at']),
            'drivers' => Employee::query()->where('staff_type', 'driver')->select(['id', 'company_id', 'employee_number', 'name', 'phone', 'email', 'status', 'availability_status', 'created_at']),
        };
    }

    private function applyFilters(Builder $query, string $resource, ?string $search, ?string $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        if ($search === null || $search === '') {
            return;
        }
        $columns = match ($resource) {
            'passengers', 'platform-staff' => ['name', 'email', 'phone'],
            'buses' => ['registration_number', 'model'],
            'trips' => ['currency'],
            'bookings' => ['reference', 'contact_name', 'contact_email', 'contact_phone'],
            'payments' => ['provider', 'provider_reference'],
            'agents' => ['name', 'code'],
            'drivers' => ['name', 'employee_number', 'phone', 'email'],
        };
        $query->where(function (Builder $builder) use ($columns, $search): void {
            foreach ($columns as $column) {
                $builder->orWhere($column, 'like', "%{$search}%");
            }
        });
    }
}
