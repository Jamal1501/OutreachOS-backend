<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceUserOnboardingState;
use Illuminate\Http\Request;
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

        $state = WorkspaceUserOnboardingState::query()->firstOrNew([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
        ]);
        $state->version = 2;

        if ((bool) ($validated['reset'] ?? false)) {
            $state->dismissed_routes = [];
            $state->hidden_at = null;
            $state->last_route = null;
        } else {
            if (array_key_exists('dismissedRoutes', $validated)) {
                $state->dismissed_routes = array_values(array_intersect(
                    self::ROUTES,
                    array_map(fn ($route) => Str::start(trim((string) $route), '/'), $validated['dismissedRoutes'])
                ));
            }
            if (array_key_exists('hidden', $validated)) {
                $state->hidden_at = $validated['hidden'] ? now() : null;
            }
            if (array_key_exists('lastRoute', $validated)) {
                $lastRoute = Str::start(trim((string) ($validated['lastRoute'] ?? '')), '/');
                $state->last_route = in_array($lastRoute, self::ROUTES, true) ? $lastRoute : null;
            }
        }

        $state->save();

        return response()->json([
            'message' => 'Personal onboarding state updated',
            'data' => $this->payload($state->fresh()),
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
