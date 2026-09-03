<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformResource;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewModerationController extends Controller
{
    public function report(Request $request, Review $review): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        abort_if($review->reported_at !== null, 409, 'This review has already been reported and is awaiting moderation.');
        $review->update(['reported_by' => $request->user()->id, 'reported_at' => now(), 'report_reason' => $validated['reason']]);

        return response()->json($review->refresh());
    }

    public function respond(Request $request, Review $review): JsonResponse
    {
        abort_unless($request->user()->company_id === $review->company_id && $request->user()->can('marketing.manage'), 404);
        $validated = $request->validate(['response' => ['required', 'string', 'max:2000']]);
        $review->update(['company_response' => $validated['response'], 'company_responded_at' => now()]);

        return response()->json($review->refresh());
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);
        $validated = $request->validate(['status' => ['nullable', Rule::in(['reported', 'active', 'removed'])]]);
        $query = Review::query()->when(($validated['status'] ?? null) === 'reported', fn (Builder $builder) => $builder->whereNotNull('reported_at'))
            ->when(($validated['status'] ?? null) === 'active', fn (Builder $builder) => $builder->where('status', 'active'))
            ->when(($validated['status'] ?? null) === 'removed', fn (Builder $builder) => $builder->where('status', 'removed'));

        return response()->json($query->latest()->paginate(25));
    }

    public function approve(Request $request, Review $review): JsonResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);
        abort_unless($review->reported_at !== null, 409, 'This review was not reported.');
        $review->update(['status' => 'active', 'reported_by' => null, 'reported_at' => null, 'report_reason' => null, 'moderated_by' => $request->user()->id, 'moderated_at' => now(), 'moderation_reason' => 'Reviewed and kept published.']);

        return response()->json($review->refresh());
    }

    public function remove(Request $request, Review $review): JsonResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $review->update(['status' => 'removed', 'moderated_by' => $request->user()->id, 'moderated_at' => now(), 'moderation_reason' => $validated['reason']]);

        return response()->json($review->refresh());
    }

    public function warnUser(Request $request, Review $review): JsonResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);
        abort_unless($review->user_id, 422, 'This review has no author account to warn.');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $warning = (new PlatformResource)->useModule('security_events');
        $warning->fill(['company_id' => $review->company_id, 'user_id' => $review->user_id, 'code' => 'warning:review:'.$review->id.':'.now()->format('YmdHis'), 'name' => 'User warning', 'status' => 'recorded', 'data' => ['record_type' => Review::class, 'record_id' => $review->id, 'event' => 'user_warning', 'reason' => $validated['reason'], 'issued_by' => $request->user()->id]]);
        $warning->save();

        return response()->json($warning, 201);
    }

    public function analytics(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        abort_unless($companyId ? $request->user()->can('marketing.manage') || $request->user()->can('reports.view') : in_array($request->user()->role, config('platform.platform_roles'), true), 403);
        $reviews = Review::query()->where('status', 'active')->when($companyId, fn (Builder $builder) => $builder->where('company_id', $companyId))->get();

        return response()->json([
            'total_reviews' => $reviews->count(),
            'average_overall' => $reviews->isEmpty() ? null : round($reviews->avg('overall_experience'), 2),
            'average_by_dimension' => collect(['cleanliness', 'comfort', 'punctuality', 'driver_professionalism', 'customer_service'])->mapWithKeys(fn (string $dimension) => [$dimension => $reviews->isEmpty() ? null : round($reviews->avg($dimension), 2)]),
            'distribution' => $reviews->groupBy(fn (Review $review) => (int) round((float) $review->overall_experience))->map->count(),
            'reported_pending' => Review::whereNotNull('reported_at')->when($companyId, fn (Builder $builder) => $builder->where('company_id', $companyId))->count(),
        ]);
    }
}
