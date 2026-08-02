<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = Str::lower(trim((string) $request->user()?->email));
        $allowed = array_map(
            fn ($value) => Str::lower(trim((string) $value)),
            (array) config('outreach.provider_spend.operator_emails', []),
        );

        if ($email === '' || ! in_array($email, $allowed, true)) {
            return response()->json([
                'error' => 'Operator access required.',
            ], 403);
        }

        return $next($request);
    }
}
