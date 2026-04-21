<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkspaceRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = strtolower(trim((string) $request->attributes->get('workspace_role')));
        $allowed = array_map(fn ($value) => strtolower(trim((string) $value)), $roles ?: ['owner', 'admin']);

        if ($request->attributes->get('legacy_api_access')) {
            return response()->json([
                'error' => 'Legacy API key access is not allowed for role-restricted actions.',
            ], 403);
        }

        if ($role === '' || !in_array($role, $allowed, true)) {
            return response()->json([
                'error' => 'You do not have permission to perform this action.',
                'requiredRoles' => $allowed,
                'currentRole' => $role ?: null,
            ], 403);
        }

        return $next($request);
    }
}
