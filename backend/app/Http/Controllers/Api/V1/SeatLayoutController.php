<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\SeatLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SeatLayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(SeatLayout::where('company_id', $this->companyId($request))->orderBy('name')->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $layout = SeatLayout::create([...$request->validate($this->rules()), 'company_id' => $this->companyId($request)]);

        return response()->json(['data' => $layout], 201);
    }

    public function show(Request $request, SeatLayout $seatLayout): JsonResponse
    {
        $this->authorizeLayout($request, $seatLayout);

        return response()->json(['data' => $seatLayout]);
    }

    public function update(Request $request, SeatLayout $seatLayout): JsonResponse
    {
        $this->authorizeLayout($request, $seatLayout);
        $seatLayout->update($request->validate($this->rules(true)));

        return response()->json(['data' => $seatLayout->refresh()]);
    }

    public function destroy(Request $request, SeatLayout $seatLayout): JsonResponse
    {
        $this->authorizeLayout($request, $seatLayout);
        abort_if(Bus::where('seat_layout_id', $seatLayout->id)->exists(), 409, 'This layout is assigned to a bus.');
        $seatLayout->delete();

        return response()->json(status: 204);
    }

    public function apply(Request $request, SeatLayout $seatLayout, Bus $bus): JsonResponse
    {
        $this->authorizeLayout($request, $seatLayout);
        abort_unless($bus->company_id === $seatLayout->company_id, 404);
        $seatElements = collect($seatLayout->elements)->where('kind', 'seat');
        abort_unless($seatElements->count() === $bus->seat_capacity, 422, 'The layout seat count must match the bus seating capacity.');

        DB::transaction(function () use ($bus, $seatLayout, $seatElements): void {
            $bus->seats()->delete();
            $seatElements->each(fn (array $element) => $bus->seats()->create(['number' => $element['label'], 'type' => $element['class'] ?? 'standard', 'accessible' => $element['accessible'] ?? false, 'row' => $element['row'], 'column' => $element['column'], 'position' => $element['position'] ?? null, 'berth_level' => $element['berth_level'] ?? null, 'deck' => $element['deck'] ?? 'lower', 'active' => $element['active'] ?? true]));
            $bus->update(['seat_layout_id' => $seatLayout->id, 'class' => $seatLayout->class]);
        });

        return response()->json(['data' => $bus->refresh()->load(['seatLayout', 'seats'])]);
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user()->company_id && $request->user()->can('fleet.manage'), 403);

        return $request->user()->company_id;
    }

    private function authorizeLayout(Request $request, SeatLayout $seatLayout): void
    {
        abort_unless($seatLayout->company_id === $this->companyId($request), 404);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return ['name' => [$required, 'string', 'max:150'], 'class' => [$required, Rule::in(['standard', 'executive', 'sleeper'])], 'rows' => [$required, 'integer', 'between:1,30'], 'columns' => [$required, 'integer', 'between:1,10'], 'elements' => [$required, 'array', 'min:1'], 'elements.*.kind' => ['required', Rule::in(['seat', 'aisle', 'driver', 'door', 'toilet'])], 'elements.*.row' => ['required', 'integer', 'min:1'], 'elements.*.column' => ['required', 'integer', 'min:1'], 'elements.*.label' => ['required_if:elements.*.kind,seat', 'string', 'max:20'], 'elements.*.position' => ['nullable', Rule::in(['window', 'aisle', 'middle'])], 'elements.*.class' => ['nullable', Rule::in(['standard', 'premium', 'accessible', 'sleeper'])], 'elements.*.berth_level' => ['nullable', Rule::in(['lower', 'upper'])], 'elements.*.deck' => ['nullable', Rule::in(['lower', 'upper'])], 'elements.*.accessible' => ['nullable', 'boolean'], 'elements.*.active' => ['nullable', 'boolean'], 'active' => ['sometimes', 'boolean']];
    }
}
