<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveRecord;
use App\Models\StaffAssignment;
use App\Models\StaffReport;
use App\Models\TrainingRecord;
use App\Models\Trip;
use App\Models\WorkingHour;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['staff_type' => ['nullable', 'string', 'max:50'], 'availability' => ['nullable', 'string', 'max:50'], 'search' => ['nullable', 'string', 'max:100']]);
        $employees = Employee::query()->where('company_id', $this->companyId($request))
            ->when($validated['staff_type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('staff_type', $type))
            ->when($validated['availability'] ?? null, fn (Builder $query, string $status): Builder => $query->where('availability_status', $status))
            ->when($validated['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('employee_number', 'like', "%{$search}%")))
            ->with(['assignments' => fn ($query) => $query->where('assigned_until', '>=', now())->orderBy('assigned_from'), 'trainingRecords' => fn ($query) => $query->orderByDesc('completed_on'), 'documents' => fn ($query) => $query->orderBy('expires_on'), 'reports' => fn ($query) => $query->latest('occurred_at')->limit(10)])->paginate(25);

        return response()->json($employees);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate($this->employeeRules($companyId));
        if ($validated['staff_type'] === 'driver') {
            validator($validated, ['driver_licence_number' => ['required'], 'driver_licence_class' => ['required'], 'driver_licence_expires_on' => ['required', 'date', 'after:today']])->validate();
        }
        $employee = Employee::create([...$validated, 'company_id' => $companyId, 'code' => $validated['employee_number'], 'status' => 'active']);

        return response()->json($employee, 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request, $employee);
        $employee->update($request->validate($this->employeeRules($employee->company_id, true, $employee)));

        return response()->json($employee->refresh());
    }

    public function assign(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request, $employee);
        $validated = $request->validate(['trip_id' => ['required', 'integer', 'exists:trips,id'], 'duty_role' => ['required', Rule::in(['driver', 'conductor', 'terminal_officer'])]]);
        $trip = Trip::whereKey($validated['trip_id'])->where('company_id', $employee->company_id)->firstOrFail();
        abort_unless($employee->status === 'active' && $employee->availability_status === 'available', 409, 'Employee is not available.');
        abort_unless($employee->staff_type === $validated['duty_role'] || $validated['duty_role'] === 'terminal_officer', 422, 'Employee type does not match the duty role.');
        if ($validated['duty_role'] === 'driver') {
            abort_unless($employee->driver_licence_expires_on?->isAfter($trip->arrives_at), 409, 'Driver licence expires before this trip is completed.');
            $hours = WorkingHour::query()->where('employee_id', $employee->id)->where('clocked_in_at', '>=', $trip->departs_at->copy()->subDay())->get()->sum(fn (WorkingHour $record): float => $record->clocked_out_at ? max(0, $record->clocked_in_at->diffInMinutes($record->clocked_out_at) - $record->break_minutes) / 60 : 0);
            abort_if($hours + ($trip->departs_at->diffInMinutes($trip->arrives_at) / 60) > 14, 409, 'Assignment would exceed safe working-hour limits.');
        }
        $onLeave = LeaveRecord::query()->where('employee_id', $employee->id)->where('status', 'approved')->whereDate('starts_on', '<=', $trip->arrives_at)->whereDate('ends_on', '>=', $trip->departs_at)->exists();
        abort_if($onLeave, 409, 'Employee is on approved leave.');
        $overlap = StaffAssignment::query()->where('employee_id', $employee->id)->whereIn('status', ['assigned', 'checked_in'])->where('assigned_from', '<', $trip->arrives_at)->where('assigned_until', '>', $trip->departs_at)->exists();
        abort_if($overlap, 409, 'Employee already has an overlapping assignment.');
        $assignment = StaffAssignment::create(['company_id' => $employee->company_id, 'user_id' => $request->user()->id, 'employee_id' => $employee->id, 'trip_id' => $trip->id, 'code' => $employee->employee_number.':'.$trip->id.':'.$validated['duty_role'], 'name' => str($validated['duty_role'])->headline(), 'duty_role' => $validated['duty_role'], 'status' => 'assigned', 'assigned_from' => $trip->departs_at, 'assigned_until' => $trip->arrives_at]);

        return response()->json($assignment, 201);
    }

    public function activate(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request, $employee);
        abort_unless($employee->status === 'suspended', 409, 'Only suspended staff can be reactivated.');
        $employee->update(['status' => 'active', 'availability_status' => 'available']);

        return response()->json($employee->refresh());
    }

    public function suspend(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request, $employee);
        abort_unless($employee->status === 'active', 409, 'Only active staff can be suspended.');
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $upcomingAssignment = StaffAssignment::query()->where('employee_id', $employee->id)->whereIn('status', ['assigned', 'checked_in'])->where('assigned_until', '>=', now())->exists();
        abort_if($upcomingAssignment, 409, 'Reassign upcoming trips before suspending this staff member.');
        $employee->update(['status' => 'suspended', 'availability_status' => 'suspended', 'data' => [...($employee->data ?? []), 'suspension_reason' => $validated['reason'] ?? null, 'suspended_at' => now()->toIso8601String()]]);

        return response()->json($employee->refresh());
    }

    public function requestLeave(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request, $employee);
        $validated = $request->validate(['leave_type' => ['required', Rule::in(['annual', 'sick', 'family', 'unpaid'])], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $record = LeaveRecord::create([...$validated, 'company_id' => $employee->company_id, 'user_id' => $request->user()->id, 'employee_id' => $employee->id, 'code' => $employee->employee_number.':'.now()->format('YmdHis'), 'name' => str($validated['leave_type'])->headline().' leave', 'status' => 'pending', 'data' => ['notes' => $validated['notes'] ?? null]]);

        return response()->json($record, 201);
    }

    public function approveLeave(Request $request, LeaveRecord $leave): JsonResponse
    {
        $employee = Employee::findOrFail($leave->employee_id);
        $this->authorizeEmployee($request, $employee);
        abort_unless($leave->status === 'pending', 409, 'Leave request was already decided.');
        $hasAssignment = StaffAssignment::query()->where('employee_id', $employee->id)->whereIn('status', ['assigned', 'checked_in'])->whereDate('assigned_from', '<=', $leave->ends_on)->whereDate('assigned_until', '>=', $leave->starts_on)->exists();
        abort_if($hasAssignment, 409, 'Reassign scheduled trips before approving leave.');
        $leave->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);

        return response()->json($leave->refresh());
    }

    public function training(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request, $employee);
        $validated = $request->validate(['course_name' => ['required', 'string', 'max:150'], 'provider' => ['nullable', 'string', 'max:150'], 'completed_on' => ['required', 'date', 'before_or_equal:today'], 'expires_on' => ['nullable', 'date', 'after:completed_on'], 'certificate_path' => ['nullable', 'string', 'max:2048']]);
        $record = TrainingRecord::create([...$validated, 'company_id' => $employee->company_id, 'user_id' => $request->user()->id, 'employee_id' => $employee->id, 'code' => $employee->employee_number.':'.str($validated['course_name'])->slug(), 'name' => $validated['course_name'], 'status' => 'completed']);

        return response()->json($record, 201);
    }

    public function storeDocument(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request, $employee);
        $validated = $request->validate(['document_type' => ['required', Rule::in(['identity', 'licence', 'employment', 'medical', 'training'])], 'reference' => ['nullable', 'string', 'max:100'], 'issued_on' => ['nullable', 'date'], 'expires_on' => ['nullable', 'date', 'after:issued_on'], 'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $path = $validated['document']->store("companies/{$employee->company_id}/staff/{$employee->id}/documents", ['disk' => 'local']);
        unset($validated['document']);
        $document = EmployeeDocument::create([...$validated, 'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'file_path' => $path]);

        return response()->json($document, 201);
    }

    public function storeReport(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request, $employee);
        $validated = $request->validate(['type' => ['required', Rule::in(['performance', 'incident'])], 'occurred_at' => ['required', 'date'], 'rating' => ['nullable', 'numeric', 'between:0,5'], 'status' => ['sometimes', Rule::in(['open', 'under_review', 'resolved', 'final'])], 'notes' => ['nullable', 'string', 'max:3000'], 'details' => ['nullable', 'array']]);
        $report = StaffReport::create([...$validated, 'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'reported_by' => $request->user()->id]);
        if ($validated['type'] === 'performance' && isset($validated['rating'])) {
            $employee->update(['rating' => $employee->reports()->where('type', 'performance')->whereNotNull('rating')->avg('rating')]);
        }

        return response()->json($report, 201);
    }

    public function storeWorkingHours(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request, $employee);
        $validated = $request->validate(['trip_id' => ['nullable', Rule::exists('trips', 'id')->where('company_id', $employee->company_id)], 'clocked_in_at' => ['required', 'date'], 'clocked_out_at' => ['nullable', 'date', 'after:clocked_in_at'], 'break_minutes' => ['sometimes', 'integer', 'between:0,720']]);
        $record = WorkingHour::create([...$validated, 'company_id' => $employee->company_id, 'user_id' => $request->user()->id, 'employee_id' => $employee->id, 'code' => $employee->employee_number.':'.now()->format('YmdHis'), 'name' => 'Working hours', 'status' => isset($validated['clocked_out_at']) ? 'completed' : 'active']);

        return response()->json($record, 201);
    }

    public function alerts(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $licences = Employee::query()->where('company_id', $companyId)->whereNotNull('driver_licence_expires_on')->whereBetween('driver_licence_expires_on', [today(), today()->addDays(60)])->orderBy('driver_licence_expires_on')->get();
        $training = TrainingRecord::query()->where('company_id', $companyId)->whereNotNull('expires_on')->whereBetween('expires_on', [today(), today()->addDays(60)])->with('employee')->orderBy('expires_on')->get();
        $documents = EmployeeDocument::query()->where('company_id', $companyId)->whereNotNull('expires_on')->whereBetween('expires_on', [today(), today()->addDays(60)])->with('employee')->orderBy('expires_on')->get();

        return response()->json(['licences' => $licences, 'training' => $training, 'documents' => $documents]);
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user()->company_id && ($request->user()->can('fleet.manage') || $request->user()->can('companies.manage')), 403);

        return $request->user()->company_id;
    }

    private function authorizeEmployee(Request $request, Employee $employee): void
    {
        abort_unless($employee->company_id === $this->companyId($request), 404);
    }

    /** @return array<string, mixed> */
    private function employeeRules(int $companyId, bool $partial = false, ?Employee $employee = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return ['employee_number' => [$required, 'string', 'max:50', Rule::unique('employees')->where('company_id', $companyId)->ignore($employee)], 'name' => [$required, 'string', 'max:150'], 'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $companyId)], 'staff_type' => [$required, Rule::in(['driver', 'conductor', 'terminal_officer', 'booking_clerk', 'maintenance_officer', 'manager'])], 'branch_id' => ['nullable', Rule::exists('company_branches', 'id')->where('company_id', $companyId)], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'], 'identity_number' => ['nullable', 'string', 'max:100'], 'identity_documents' => ['nullable', 'array'], 'hired_on' => ['nullable', 'date', 'before_or_equal:today'], 'employment_type' => ['sometimes', Rule::in(['full_time', 'part_time', 'contract'])], 'availability_status' => ['sometimes', Rule::in(['available', 'assigned', 'on_leave', 'suspended'])], 'driver_licence_number' => ['nullable', 'string', 'max:100'], 'driver_licence_class' => ['nullable', 'string', 'max:20'], 'driver_licence_expires_on' => ['nullable', 'date'], 'emergency_contact' => ['nullable', 'array'], 'manifest_access' => ['sometimes', 'boolean'], 'ticket_scanning_access' => ['sometimes', 'boolean']];
    }
}
