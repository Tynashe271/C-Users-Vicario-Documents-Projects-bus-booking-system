<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCompany
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->user()?->company;
        if ($company && in_array($company->status, ['suspended', 'rejected', 'closed'], true)) {
            abort(403, 'This company account cannot perform operational actions.');
        }

        return $next($request);
    }
}
