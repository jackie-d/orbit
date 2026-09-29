<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Orbit is an API-only application: always negotiate JSON so that
 * validation errors, 401s and 404s are rendered as JSON documents.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Accept') || $request->headers->get('Accept') === '*/*') {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
