<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Bus;
use App\Models\BusDocument;
use App\Models\MaintenanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FleetController extends Controller
{
    public function storeBus(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate($this->busRules($companyId));
        $bus = Bus::create([...$validated, 'company_id' => $companyId]);

        return response()->json($bus, 201);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $statuses = Bus::query()->where('company_id', $companyId)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $expiringDocuments = BusDocument::query()->where('company_id', $companyId)->whereNotNull('expires_on')->whereBetween('expires_on', [today(), today()->addDays(30)])->with('bus:id,registration_number')->orderBy('expires_on')->get();
        $overdueMaintenance = MaintenanceRecord::query()->where('company_id', $companyId)->whereNull('completed_at')->where('scheduled_at', '<', now())->with('bus:id,registration_number')->orderBy('scheduled_at')->get();

        return response()->json(['fleet_total' => $statuses->sum(), 'by_status' => $statuses, 'expiring_documents' => $expiringDocuments, 'overdue_maintenance' => $overdueMaintenance]);
    }

    public function buses(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in($this->statuses())], 'search' => ['nullable', 'string', 'max:100']]);
        $buses = Bus::query()->where('company_id', $this->companyId($request))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($validated['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $nested) => $nested->where('registration_number', 'like', "%{$search}%")->orWhere('model', 'like', "%{$search}%")))
            ->withCount(['seats' => fn (Builder $query) => $query->where('active', true)])->with(['documents' => fn ($query) => $query->orderBy('expires_on'), 'seatLayout'])->latest()->paginate(25);

        return response()->json($buses);
    }

    public function updateBus(Request $request, Bus $bus): JsonResponse
    {
        $this->authorizeBus($request, $bus);
        $validated = $request->validate([
            ...$this->busRules($bus->company_id, true, $bus),
            'manufacturing_year' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'ownership_status' => ['sometimes', Rule::in(['owned', 'leased', 'financed', 'contracted'])],
            'current_branch_id' => ['sometimes', 'nullable', Rule::exists('company_branches', 'id')->where('company_id', $bus->company_id)],
            'mileage_km' => ['sometimes', 'integer', 'gte:'.$bus->mileage_km], 'gps_device_identifier' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('buses')->ignore($bus)],
        ]);
        abort_if(isset($validated['status']) && in_array($validated['status'], ['assigned', 'in_transit'], true) && $bus->status === 'under_maintenance', 409, 'Complete open maintenance before assigning this bus.');
        $bus->update($validated);

        return response()->json($bus->refresh()->load(['documents', 'maintenanceRecords']));
    }

    public function records(Request $request, Bus $bus): JsonResponse
    {
        $this->authorizeBus($request, $bus);
        $validated = $request->validate(['type' => ['nullable', Rule::in($this->recordTypes())]]);

        return response()->json($bus->operationalRecords()->when($validated['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type))->latest('occurred_at')->paginate(50));
    }

    public function storeRecord(Request $request, Bus $bus): JsonResponse
    {
        $this->authorizeBus($request, $bus);
        $validated = $request->validate(['type' => ['required', Rule::in($this->recordTypes())], 'reference' => ['nullable', 'string', 'max:100'], 'occurred_at' => ['required', 'date'], 'odometer_km' => ['nullable', 'integer', 'gte:'.$bus->mileage_km], 'quantity' => ['nullable', 'numeric', 'min:0'], 'amount' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'status' => ['sometimes', 'string', 'max:50'], 'notes' => ['nullable', 'string', 'max:3000'], 'details' => ['nullable', 'array']]);
        $record = $bus->operationalRecords()->create([...$validated, 'company_id' => $bus->company_id, 'recorded_by' => $request->user()->id]);
        if (isset($validated['odometer_km']) && $validated['odometer_km'] > $bus->mileage_km) {
            $bus->update(['mileage_km' => $validated['odometer_km']]);
        }

        return response()->json($record, 201);
    }

    public function transfer(Request $request, Bus $bus): JsonResponse
    {
        $this->authorizeBus($request, $bus);
        $validated = $request->validate(['branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $bus->company_id)], 'notes' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($bus, $validated, $request): void {
            $bus->operationalRecords()->create(['company_id' => $bus->company_id, 'branch_id' => $validated['branch_id'], 'recorded_by' => $request->user()->id, 'type' => 'transfer', 'occurred_at' => now(), 'notes' => $validated['notes'] ?? null, 'details' => ['from_branch_id' => $bus->current_branch_id, 'to_branch_id' => $validated['branch_id']]]);
            $bus->update(['current_branch_id' => $validated['branch_id']]);
        });

        return response()->json($bus->refresh());
    }

    public function replace(Request $request, Bus $bus): JsonResponse
    {
        $this->authorizeBus($request, $bus);
        $validated = $request->validate(['replacement_bus_id' => ['required', Rule::exists('buses', 'id')->where(fn ($query) => $query->where('company_id', $bus->company_id)->where('id', '<>', $bus->id))], 'notes' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($bus, $validated, $request): void {
            $bus->update(['replaced_by_bus_id' => $validated['replacement_bus_id'], 'status' => 'retired', 'retired_at' => now()]);
            $bus->operationalRecords()->create(['company_id' => $bus->company_id, 'recorded_by' => $request->user()->id, 'type' => 'replacement', 'occurred_at' => now(), 'notes' => $validated['notes'] ?? null, 'details' => ['replacement_bus_id' => $validated['replacement_bus_id']]]);
        });

        return response()->json($bus->refresh());
    }

    public function amenities(Request $request): JsonResponse
    {
        return response()->json(Amenity::where('company_id', $this->companyId($request))->orderBy('name')->get());
    }

    public function storeAmenity(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate(['name' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:50', Rule::unique('amenities')->where('company_id', $companyId)], 'description' => ['nullable', 'string', 'max:500']]);
        $amenity = Amenity::create(['company_id' => $companyId, 'user_id' => $request->user()->id, 'name' => $validated['name'], 'code' => $validated['code'], 'status' => 'active', 'data' => ['description' => $validated['description'] ?? null]]);

        return response()->json($amenity, 201);
    }

    public function storeDocument(Request $request, Bus $bus): JsonResponse
    {
        $this->authorizeBus($request, $bus);
        $validated = $request->validate(['document_type' => ['required', Rule::in(['registration', 'insurance', 'permit', 'roadworthiness', 'ownership'])], 'code' => ['required', 'string', 'max:100'], 'issued_on' => ['nullable', 'date'], 'expires_on' => ['nullable', 'date', 'after:issued_on'], 'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $path = $validated['document']->store("companies/{$bus->company_id}/buses/{$bus->id}/documents", ['disk' => 'local']);
        unset($validated['document']);
        $document = $bus->documents()->create([...$validated, 'file_path' => $path, 'company_id' => $bus->company_id, 'name' => str($validated['document_type'])->headline(), 'status' => 'pending']);

        return response()->json($document, 201);
    }

    public function scheduleMaintenance(Request $request, Bus $bus): JsonResponse
    {
        $this->authorizeBus($request, $bus);
        $validated = $request->validate(['maintenance_type' => ['required', Rule::in(['preventive', 'repair', 'inspection', 'roadworthiness', 'tyres', 'service'])], 'scheduled_at' => ['required', 'date'], 'odometer_km' => ['nullable', 'integer', 'gte:'.$bus->mileage_km], 'amount' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'vendor' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:2000'], 'assigned_technician_id' => ['nullable', Rule::exists('employees', 'id')->where('company_id', $bus->company_id)], 'parts_used' => ['nullable', 'array'], 'parts_used.*.name' => ['required', 'string', 'max:100'], 'parts_used.*.quantity' => ['required', 'numeric', 'min:0.01'], 'next_service_on' => ['nullable', 'date', 'after:scheduled_at'], 'next_service_odometer_km' => ['nullable', 'integer', 'gt:'.$bus->mileage_km]]);
        $record = $bus->maintenanceRecords()->create([...$validated, 'company_id' => $bus->company_id, 'name' => str($validated['maintenance_type'])->headline(), 'status' => 'scheduled']);

        return response()->json($record, 201);
    }

    public function approveMaintenance(Request $request, MaintenanceRecord $maintenance): JsonResponse
    {
        $bus = $maintenance->bus()->firstOrFail();
        $this->authorizeBus($request, $bus);
        abort_unless(in_array($maintenance->status, ['pending_approval', 'scheduled'], true), 409, 'Only pending or scheduled maintenance can be approved.');
        $maintenance->update(['status' => 'scheduled', 'approved_by' => $request->user()->id, 'approved_at' => now()]);

        return response()->json($maintenance->refresh());
    }

    public function startMaintenance(Request $request, MaintenanceRecord $maintenance): JsonResponse
    {
        $bus = $maintenance->bus()->firstOrFail();
        $this->authorizeBus($request, $bus);
        abort_unless($maintenance->status === 'scheduled', 409, 'Only scheduled maintenance can be started.');
        DB::transaction(function () use ($maintenance, $bus): void {
            $maintenance->update(['status' => 'in_progress', 'starts_at' => now()]);
            $bus->update(['status' => 'under_maintenance']);
        });

        return response()->json($maintenance->refresh());
    }

    public function completeMaintenance(Request $request, MaintenanceRecord $maintenance): JsonResponse
    {
        $bus = $maintenance->bus()->firstOrFail();
        $this->authorizeBus($request, $bus);
        abort_unless($maintenance->status === 'in_progress', 409, 'Only maintenance in progress can be completed.');
        $validated = $request->validate(['odometer_km' => ['required', 'integer', 'gte:'.$bus->mileage_km], 'condition_rating' => ['nullable', Rule::in(['excellent', 'good', 'fair', 'poor', 'critical'])], 'notes' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($maintenance, $bus, $validated): void {
            $completedAt = now();
            $downtimeMinutes = $maintenance->starts_at?->diffInMinutes($completedAt);
            $maintenance->update(['status' => 'completed', 'completed_at' => $completedAt, 'downtime_minutes' => $downtimeMinutes, ...$validated]);
            $bus->update(['status' => 'available', 'mileage_km' => $validated['odometer_km']]);
        });

        return response()->json($maintenance->refresh());
    }

    public function maintenanceAlerts(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $dueByDate = MaintenanceRecord::query()->where('company_id', $companyId)->whereNotNull('next_service_on')->whereBetween('next_service_on', [today(), today()->addDays(30)])->with('bus:id,registration_number,mileage_km')->orderByDesc('id')->get()->unique('bus_id')->sortBy('next_service_on')->values();
        $dueByOdometer = MaintenanceRecord::query()->where('company_id', $companyId)->whereNotNull('next_service_odometer_km')->with('bus:id,registration_number,mileage_km')->orderByDesc('id')->get()->unique('bus_id')->filter(fn (MaintenanceRecord $record): bool => $record->bus && $record->next_service_odometer_km - $record->bus->mileage_km <= 1000)->values();

        return response()->json(['due_by_date' => $dueByDate, 'due_by_odometer' => $dueByOdometer]);
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user()->can('fleet.manage') && $request->user()->company_id, 403);

        return $request->user()->company_id;
    }

    private function authorizeBus(Request $request, Bus $bus): void
    {
        abort_unless($bus->company_id === $this->companyId($request), 404);
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return ['available', 'assigned', 'boarding', 'in_transit', 'under_maintenance', 'out_of_service', 'suspended', 'retired'];
    }

    /** @return list<string> */
    private function recordTypes(): array
    {
        return ['mileage', 'fuel', 'inspection', 'incident', 'transfer', 'replacement'];
    }

    /** @return array<string, mixed> */
    private function busRules(int $companyId, bool $partial = false, ?Bus $bus = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return ['registration_number' => [$required, 'string', 'max:30', Rule::unique('buses')->ignore($bus)], 'vin' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('buses')->ignore($bus)], 'manufacturer' => ['sometimes', 'nullable', 'string', 'max:100'], 'model' => [$required, 'string', 'max:100'], 'class' => [$required, 'string', 'max:50'], 'manufacturing_year' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)], 'seat_capacity' => [$required, 'integer', 'between:1,100'], 'seat_layout_id' => ['sometimes', 'nullable', Rule::exists('seat_layout_definitions', 'id')->where('company_id', $companyId)], 'ownership_status' => ['sometimes', Rule::in(['owned', 'leased', 'financed', 'contracted'])], 'current_branch_id' => ['sometimes', 'nullable', Rule::exists('company_branches', 'id')->where('company_id', $companyId)], 'mileage_km' => ['sometimes', 'integer', 'min:0'], 'gps_device_identifier' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('buses')->ignore($bus)], 'status' => ['sometimes', Rule::in($this->statuses())], 'amenities' => ['sometimes', 'array'], 'amenities.*' => ['string', 'max:50', 'distinct'], 'images' => ['sometimes', 'array', 'max:10'], 'images.*' => ['url', 'max:2048']];
    }
}
