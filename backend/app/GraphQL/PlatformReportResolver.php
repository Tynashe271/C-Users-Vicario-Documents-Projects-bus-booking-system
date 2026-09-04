<?php

namespace App\GraphQL;

use App\Http\Controllers\Api\V1\GlobalReportsController;
use GraphQL\Error\Error;
use Illuminate\Support\Carbon;

class PlatformReportResolver
{
    /**
     * @param  array{from?:string, to?:string}  $arguments
     * @return array{json: string}
     */
    public function __invoke(mixed $root, array $arguments): array
    {
        $user = auth('sanctum')->user();
        if (! $user) {
            throw new Error('Unauthenticated.');
        }
        if (! in_array($user->role, config('platform.platform_roles'), true) || ! $user->can('reports.view')) {
            throw new Error('Forbidden.');
        }
        try {
            $from = isset($arguments['from']) ? Carbon::parse($arguments['from'])->startOfDay() : now()->subDays(30)->startOfDay();
            $to = isset($arguments['to']) ? Carbon::parse($arguments['to'])->endOfDay() : now()->endOfDay();
        } catch (\Exception) {
            throw new Error('Invalid date.');
        }

        return ['json' => json_encode((new GlobalReportsController)->build($from, $to), JSON_THROW_ON_ERROR)];
    }
}
