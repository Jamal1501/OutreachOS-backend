<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceUserOnboardingState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceOnboardingController extends Controller
{
    private const ROUTES = [
        '/dashboard',
        '/discover',
        '/crm',
        '/outreach',
        '/tasks',
        '/settings',
    ];

    public function show(Request $request)
    {
        [$workspace, $userId] = $this->context($request);
        if (! $workspace || $userId === '') {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        $state = WorkspaceUserOnboardingState::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();

        return response()->json([
            'message' => 'Personal onboarding state fetched',
            'data' => $this->payload($state),
        ]);
    }

    public function update(Request $request)
    {
        [$workspace, $userId] = $this->context($request);
        if (! $workspace || $userId === '') {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        $validated = $request->validate([
            'dismissedRoutes' => ['sometimes', 'array', 'max:20'],
            'dismissedRoutes.*' => ['string', 'max:120'],
            'hidden' => ['sometimes', 'boolean'],
            'lastRoute' => ['nullable', 'string', 'max:120'],
            'reset' => ['sometimes', 'boolean'],
        ]);

        $state = WorkspaceUserOnboardingState::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();
        $dismissedRoutes = array_values((array) ($state?->dismissed_routes ?? []));
        $hiddenAt = $state?->hidden_at;
        $lastRoute = $state?->last_route;
        $updateColumns = ['version', 'updated_at'];

        if ((bool) ($validated['reset'] ?? false)) {
            $dismissedRoutes = [];
            $hiddenAt = null;
            $lastRoute = null;
            array_push($updateColumns, 'dismissed_routes', 'hidden_at', 'last_route');
        } else {
            if (array_key_exists('dismissedRoutes', $validated)) {
                $dismissedRoutes = array_values(array_intersect(
                    self::ROUTES,
                    array_map(fn ($route) => Str::start(trim((string) $route), '/'), $validated['dismissedRoutes'])
                ));
                $updateColumns[] = 'dismissed_routes';
            }
            if (array_key_exists('hidden', $validated)) {
                $hiddenAt = $validated['hidden'] ? now() : null;
                $updateColumns[] = 'hidden_at';
            }
            if (array_key_exists('lastRoute', $validated)) {
                $requestedRoute = Str::start(trim((string) ($validated['lastRoute'] ?? '')), '/');
                $lastRoute = in_array($requestedRoute, self::ROUTES, true) ? $requestedRoute : null;
                $updateColumns[] = 'last_route';
            }
        }

        $timestamp = now();
        DB::table('workspace_user_onboarding_states')->upsert([[
            'id' => $state?->id ?: (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'version' => 2,
            'dismissed_routes' => json_encode($dismissedRoutes),
            'hidden_at' => $hiddenAt,
            'last_route' => $lastRoute,
            'created_at' => $state?->created_at ?: $timestamp,
            'updated_at' => $timestamp,
        ]], ['workspace_id', 'user_id'], array_values(array_unique($updateColumns)));

        $state = WorkspaceUserOnboardingState::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        return response()->json([
            'message' => 'Personal onboarding state updated',
            'data' => $this->payload($state),
        ]);
    }

    private function context(Request $request): array
    {
        return [
            $request->attributes->get('workspace'),
            trim((string) $request->attributes->get('supabase_user_id')),
        ];
    }

    private function payload(?WorkspaceUserOnboardingState $state): array
    {
        return [
            'version' => (int) ($state?->version ?? 2),
            'dismissedRoutes' => array_values((array) ($state?->dismissed_routes ?? [])),
            'hiddenAt' => $state?->hidden_at?->toIso8601String(),
            'lastRoute' => $state?->last_route,
            'updatedAt' => $state?->updated_at?->toIso8601String(),
        ];
    }
}
