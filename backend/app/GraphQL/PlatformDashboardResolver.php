<?php

namespace App\GraphQL;

use App\Http\Controllers\Api\V1\AdminDashboardController;
use GraphQL\Error\Error;

class PlatformDashboardResolver
{
    /** @return array{json: string} */
    public function __invoke(mixed $root, array $arguments): array
    {
        $user = auth('sanctum')->user();
        if (! $user) {
            throw new Error('Unauthenticated.');
        }
        if (! $user->can('platform.manage')) {
            throw new Error('Forbidden.');
        }

        return ['json' => json_encode((new AdminDashboardController)->build(), JSON_THROW_ON_ERROR)];
    }
}
