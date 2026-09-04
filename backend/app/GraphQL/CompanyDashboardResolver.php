<?php

namespace App\GraphQL;

use App\Http\Controllers\Api\V1\CompanyDashboardController;
use GraphQL\Error\Error;

class CompanyDashboardResolver
{
    /** @return array{json: string} */
    public function __invoke(mixed $root, array $arguments): array
    {
        $user = auth('sanctum')->user();
        if (! $user) {
            throw new Error('Unauthenticated.');
        }
        if ($user->company && in_array($user->company->status, ['suspended', 'rejected', 'closed'], true)) {
            throw new Error('Company access is suspended.');
        }
        if (! $user->company_id || ! $user->can('reports.view')) {
            throw new Error('Forbidden.');
        }

        return ['json' => json_encode((new CompanyDashboardController)->build($user), JSON_THROW_ON_ERROR)];
    }
}
