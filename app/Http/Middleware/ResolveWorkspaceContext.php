<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ResolveWorkspaceContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = trim((string) (
            $request->header('X-Workspace-Id')
            ?: $request->input('workspaceId')
            ?: $request->query('workspaceId')
        ));

        $user = $request->user();
        $supabaseUserId = trim((string) ($request->attributes->get('supabase_user_id') ?: $user?->supabase_user_id));

        if ($request->attributes->get('legacy_api_access')) {
            return response()->json([
                'error' => 'Legacy API key access is not allowed for workspace-scoped routes.',
            ], 403);
        }

        if ($workspaceId === '' && $supabaseUserId !== '') {
            $memberships = Cache::remember(
                sprintf('workspace-context:user-memberships:%s', $supabaseUserId),
                30,
                fn () => WorkspaceMember::query()
                    ->where('user_id', $supabaseUserId)
                    ->orderBy('joined_at')
                    ->limit(2)
                    ->get(['workspace_id', 'role'])
            );

            if ($memberships->count() === 1) {
                $workspaceId = (string) $memberships->first()->workspace_id;
            }
        }

        if ($workspaceId === '') {
            return response()->json([
                'error' => 'Missing workspace context.',
            ], 400);
        }

        /** @var Workspace|null $workspace */
        $workspace = Cache::remember(
            sprintf('workspace-context:workspace:%s', $workspaceId),
            30,
            fn () => Workspace::query()->find($workspaceId)
        );
        if (! $workspace) {
            return response()->json([
                'error' => 'Workspace not found.',
            ], 404);
        }

        $settings = (array) ($workspace->settings ?? []);
        if (! empty($settings['deletedAt'])) {
            return response()->json([
                'error' => 'Workspace not found.',
            ], 404);
        }

        if (! empty($settings['archivedAt'])) {
            return response()->json([
                'error' => 'workspace_archived',
                'message' => 'This workspace is archived. Restore it from Workspace Management before using it.',
            ], 423);
        }

        $membership = null;
        if ($supabaseUserId !== '') {
            $membership = Cache::remember(
                sprintf('workspace-context:membership:%s:%s', $workspaceId, $supabaseUserId),
                30,
                fn () => WorkspaceMember::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('user_id', $supabaseUserId)
                    ->first()
            );

            if (! $membership) {
                return response()->json([
                    'error' => 'You do not have access to this workspace.',
                ], 403);
            }
        } else {
            return response()->json([
                'error' => 'Authentication required for workspace access.',
            ], 401);
        }

        $workbookId = trim((string) ($settings['workspaceDataKey'] ?? $settings['workbookId'] ?? ''));

        if ($workbookId === '') {
            $workbookId = 'workspace:'.($workspace->slug ?: $workspace->id);
        }

        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('workspace_id', $workspace->id);
        $request->attributes->set('workspace_role', $membership?->role);
        $request->attributes->set('workspace_membership', $membership);
        $request->attributes->set('workspace_workbook_id', $workbookId);

        return $next($request);
    }
}
