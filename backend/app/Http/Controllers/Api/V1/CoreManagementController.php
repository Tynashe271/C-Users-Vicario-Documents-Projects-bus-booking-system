<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Services\MappingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

class CoreManagementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, string $resource): AnonymousResourceCollection
    {
        return JsonResource::collection($this->query($request, $resource)->latest()->paginate(25));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $resource): JsonResource
    {
        $this->authorizeResource($request, $resource);
        $class = $this->resourceClass($resource);
        $data = $request->validate($this->rules($resource));
        if (! $this->isPlatformUser($request)) {
            $this->assertRelatedOwnership($request, $resource, $data);
        }
        if (in_array($resource, ['buses', 'routes', 'trips'], true) && ! $this->isPlatformUser($request)) {
            $data['company_id'] = $request->user()->company_id;
        }
        if ($resource === 'routes' && ! isset($data['distance_km'])) {
            $data['distance_km'] = $this->estimateRouteDistance($data);
        }

        return JsonResource::make($class::create($data));
    }

    /**
     * A caller that doesn't already know the road distance still gets a route it can price and
     * report on — see MappingService for why this is a real distance and not a guess.
     *
     * @param  array<string, mixed>  $data
     */
    private function estimateRouteDistance(array $data): ?int
    {
        $origin = Terminal::find($data['origin_terminal_id'] ?? null);
        $destination = Terminal::find($data['destination_terminal_id'] ?? null);
        if ($origin?->latitude === null || $origin?->longitude === null || $destination?->latitude === null || $destination?->longitude === null) {
            return null;
        }

        return (int) round(app(MappingService::class)->distanceKm((float) $origin->latitude, (float) $origin->longitude, (float) $destination->latitude, (float) $destination->longitude));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $resource, int $id): JsonResource
    {
        return JsonResource::make($this->query($request, $resource)->findOrFail($id));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $resource, int $id): JsonResource
    {
        $record = $this->query($request, $resource)->findOrFail($id);
        $record->update($request->validate($this->rules($resource, true)));

        return JsonResource::make($record->refresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $resource, int $id): JsonResponse
    {
        $this->query($request, $resource)->findOrFail($id)->delete();

        return response()->json(status: 204);
    }

    private function query(Request $request, string $resource): Builder
    {
        $this->authorizeResource($request, $resource);
        $class = $this->resourceClass($resource);
        $query = $class::query();
        if ($this->isPlatformUser($request)) {
            return $query;
        }
        abort_unless($request->user()->company_id, 403);
        if (in_array($resource, ['companies', 'terminals'], true)) {
            abort(403);
        }

        return $resource === 'seats' ? $query->whereHas('bus', fn (Builder $builder) => $builder->where('company_id', $request->user()->company_id)) : $query->where('company_id', $request->user()->company_id);
    }

    /** @return class-string<Model> */
    private function resourceClass(string $resource): string
    {
        return match ($resource) {
            'companies' => Company::class, 'terminals' => Terminal::class, 'buses' => Bus::class,
            'seats' => Seat::class, 'routes' => TransportRoute::class, 'trips' => Trip::class,
            default => abort(404, 'Unknown core resource.'),
        };
    }

    /** @return array<string, mixed> */
    private function rules(string $resource, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return match ($resource) {
            'companies' => ['name' => [$required, 'string'], 'slug' => [$required, 'string', 'max:100'], 'registration_number' => ['nullable', 'string'], 'status' => ['sometimes', 'string'], 'currency' => ['sometimes', 'string', 'size:3'], 'settings' => ['nullable', 'array']],
            'terminals' => ['name' => [$required, 'string'], 'city' => [$required, 'string'], 'country' => [$required, 'string', 'size:2'], 'latitude' => ['nullable', 'numeric'], 'longitude' => ['nullable', 'numeric'], 'type' => ['sometimes', Rule::in(['terminal', 'bus_station', 'pickup_point', 'dropoff_point', 'border_post'])], 'active' => ['sometimes', 'boolean'], 'operating_hours' => ['nullable', 'array'], 'contact_information' => ['nullable', 'array']],
            'buses' => ['company_id' => ['sometimes', 'exists:companies,id'], 'registration_number' => [$required, 'string'], 'model' => [$required, 'string'], 'class' => ['sometimes', 'string'], 'seat_capacity' => [$required, 'integer', 'min:1'], 'status' => ['sometimes', 'string'], 'amenities' => ['nullable', 'array']],
            'seats' => ['bus_id' => [$required, 'exists:buses,id'], 'number' => [$required, 'string'], 'type' => ['sometimes', 'string'], 'accessible' => ['sometimes', 'boolean']],
            'routes' => ['company_id' => ['sometimes', 'exists:companies,id'], 'origin_terminal_id' => [$required, 'exists:terminals,id'], 'destination_terminal_id' => [$required, 'different:origin_terminal_id', 'exists:terminals,id'], 'name' => [$required, 'string'], 'distance_km' => ['nullable', 'integer'], 'duration_minutes' => [$required, 'integer', 'min:1'], 'active' => ['sometimes', 'boolean'], 'status' => ['sometimes', Rule::in(['draft', 'active', 'suspended', 'retired'])], 'border_information' => ['nullable', 'array']],
            'trips' => ['company_id' => ['sometimes', 'exists:companies,id'], 'route_id' => [$required, 'exists:routes,id'], 'bus_id' => [$required, 'exists:buses,id'], 'departs_at' => [$required, 'date'], 'arrives_at' => [$required, 'date', 'after:departs_at'], 'base_fare' => [$required, 'numeric', 'min:0'], 'currency' => [$required, 'string', 'size:3'], 'status' => ['sometimes', 'string']],
            default => [],
        };
    }

    private function isPlatformUser(Request $request): bool
    {
        return in_array($request->user()->role, config('platform.platform_roles'), true);
    }

    /** @param array<string, mixed> $data */
    private function assertRelatedOwnership(Request $request, string $resource, array $data): void
    {
        $companyId = $request->user()->company_id;
        if ($resource === 'seats') {
            abort_unless(Bus::whereKey($data['bus_id'])->where('company_id', $companyId)->exists(), 403);
        }
        if ($resource === 'trips') {
            $bus = Bus::whereKey($data['bus_id'])->where('company_id', $companyId)->where('status', 'available')->first();
            abort_unless($bus?->hasApprovedOperationalDocuments(), 422, 'The bus is unavailable or does not have approved, current insurance and permit documents.');
            abort_unless(TransportRoute::whereKey($data['route_id'])->where('company_id', $companyId)->exists(), 403);
        }
    }

    private function authorizeResource(Request $request, string $resource): void
    {
        $permission = match ($resource) {
            'companies' => 'companies.manage', 'terminals', 'routes' => 'routes.manage',
            'buses', 'seats' => 'fleet.manage', 'trips' => 'trips.manage',
            default => abort(404),
        };
        abort_unless($request->user()->can($permission), 403);
    }
}
