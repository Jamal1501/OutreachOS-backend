<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAppKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = (string) config('services.app_security.key');

        if ($configuredKey === '') {
            return response()->json([
                'error' => 'API key protection is not configured on the server',
            ], 500);
        }

        $providedKey = (string) $request->header('X-APP-KEY');

        if ($providedKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
