<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
{
    public function bootstrap(Request $request)
    {
        $supabaseUserId = trim((string) $request->attributes->get('supabase_user_id'));
        if ($supabaseUserId === '') {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        $requestedWorkspaceId = trim((string) (
            $request->header('X-Workspace-Id')
            ?: $request->query('workspaceId')
        ));

        $memberships = WorkspaceMember::query()
            ->where('user_id', $supabaseUserId)
            ->orderBy('joined_at')
            ->get();

        if ($memberships->isEmpty()) {
            return response()->json([
                'message' => 'No workspace found for this user.',
                'data' => [
                    'workspace' => null,
                    'membership' => null,
                    'plan' => null,
                    'members' => [],
                ],
            ]);
        }

        if ($requestedWorkspaceId !== '') {
            $membership = $memberships->firstWhere('workspace_id', $requestedWorkspaceId);

            if (!$membership) {
                return response()->json([
                    'error' => 'workspace_not_available',
                    'message' => 'The requested workspace is not available for this user.',
                ], 403);
            }
        } else {
            $membership = $memberships->first();
        }

        $workspace = Workspace::query()->find($membership->workspace_id);
        if (!$workspace) {
            return response()->json([
                'message' => 'Workspace membership exists but workspace is missing.',
                'data' => [
                    'workspace' => null,
                    'membership' => null,
                    'plan' => null,
                    'members' => [],
                ],
            ], 404);
        }

        return response()->json([
            'message' => 'Workspace bootstrap loaded',
            'data' => $this->workspacePayload($workspace, $membership),
        ]);
    }

    public function create(Request $request)
    {
        $supabaseUserId = trim((string) $request->attributes->get('supabase_user_id'));
        if ($supabaseUserId === '') {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'platformFocus' => ['nullable', Rule::in(['instagram', 'tiktok', 'both'])],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'brandProfile' => ['nullable', 'array'],
        ]);

        [$workspace, $membership] = DB::transaction(function () use ($validated, $supabaseUserId) {
            $name = trim((string) $validated['name']);
            $slug = $this->uniqueSlug($name);
            $workspaceId = (string) Str::uuid();
            $workspaceDataKey = 'workspace:' . $slug;

            $workspace = Workspace::query()->create([
                'id' => $workspaceId,
                'name' => $name,
                'slug' => $slug,
                'owner_id' => $supabaseUserId,
                'plan_id' => 'free',
                'settings' => [
                    'workspaceDataKey' => $workspaceDataKey,
                    // Backward-compatible alias while old request payloads still use sheetId/workbookId.
                    'workbookId' => $workspaceDataKey,
                    'platformFocus' => $validated['platformFocus'] ?? 'both',
                    'budget' => isset($validated['budget']) ? (float) $validated['budget'] : null,
                    'notes' => $validated['notes'] ?? null,
                    'brandProfile' => $validated['brandProfile'] ?? null,
                    'dataSource' => 'internal_database',
                    'legacyGoogleSheetsDisabled' => true,
                ],
            ]);

            $membership = WorkspaceMember::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'user_id' => $supabaseUserId,
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            return [$workspace, $membership];
        });

        return response()->json([
            'message' => 'Workspace created',
            'data' => $this->workspacePayload($workspace->fresh(), $membership),
        ], 201);
    }


    public function invite(Request $request)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        if (!$workspace) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', Rule::in(['admin', 'member'])],
        ]);

        $email = Str::lower(trim((string) $validated['email']));
        $role = (string) ($validated['role'] ?? 'member');

        $invitation = DB::table('workspace_invitations')->updateOrInsert(
            [
                'workspace_id' => $workspace->id,
                'email' => $email,
                'accepted_at' => null,
            ],
            [
                'id' => (string) Str::uuid(),
                'role' => $role,
                'token' => (string) Str::uuid(),
                'expires_at' => now()->addDays(14),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Invitation created',
            'data' => ['email' => $email, 'role' => $role],
        ], $invitation ? 201 : 200);
    }

    public function removeMember(Request $request, string $memberId)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        /** @var WorkspaceMember|null $currentMembership */
        $currentMembership = $request->attributes->get('workspace_membership');

        if (!$workspace || !$currentMembership) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        $member = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $memberId)
            ->first();

        if (!$member) {
            return response()->json(['error' => 'Workspace member not found.'], 404);
        }

        if ($member->user_id === $currentMembership->user_id) {
            return response()->json(['error' => 'You cannot remove yourself from the workspace.'], 422);
        }

        if ($member->role === 'owner') {
            return response()->json(['error' => 'Workspace owners cannot be removed from this screen.'], 422);
        }

        $member->delete();

        return response()->json([
            'message' => 'Workspace member removed',
            'data' => $this->workspacePayload($workspace->fresh(), $currentMembership),
        ]);
    }

    public function updateSettings(Request $request)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        if (!$workspace) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        $validated = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $protectedKeys = [
            'workbookId',
            'workspaceDataKey',
            'dataSource',
            'legacyGoogleSheetsDisabled',
        ];

        $incoming = array_diff_key((array) $validated['settings'], array_flip($protectedKeys));
        $settings = array_merge((array) ($workspace->settings ?? []), $incoming, [
            'workspaceDataKey' => (string) data_get($workspace->settings, 'workspaceDataKey', 'workspace:' . $workspace->slug),
            'workbookId' => (string) data_get($workspace->settings, 'workbookId', data_get($workspace->settings, 'workspaceDataKey', 'workspace:' . $workspace->slug)),
            'dataSource' => 'internal_database',
            'legacyGoogleSheetsDisabled' => true,
        ]);

        $workspace->settings = $settings;
        $workspace->save();

        /** @var WorkspaceMember|null $membership */
        $membership = $request->attributes->get('workspace_membership');

        return response()->json([
            'message' => 'Workspace settings updated',
            'data' => $this->workspacePayload($workspace->fresh(), $membership),
        ]);
    }

    private function workspacePayload(?Workspace $workspace, ?WorkspaceMember $membership): array
    {
        if (!$workspace || !$membership) {
            return [
                'workspace' => null,
                'membership' => null,
                'plan' => null,
                'members' => [],
            ];
        }

        $settings = (array) ($workspace->settings ?? []);
        $workspaceDataKey = trim((string) ($settings['workspaceDataKey'] ?? $settings['workbookId'] ?? ''));
        if ($workspaceDataKey === '') {
            $workspaceDataKey = 'workspace:' . ($workspace->slug ?: $workspace->id);
        }
        $settings['workspaceDataKey'] = $workspaceDataKey;
        $settings['workbookId'] = $workspaceDataKey;
        $settings['dataSource'] = 'internal_database';
        $settings['legacyGoogleSheetsDisabled'] = true;

        $plan = DB::table('plans')->where('id', $workspace->plan_id ?: 'free')->first();
        $members = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->orderByRaw("CASE role WHEN 'owner' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END")
            ->orderBy('joined_at')
            ->get();

        $featuresRaw = $plan->features ?? [];
        $features = is_string($featuresRaw)
            ? (json_decode($featuresRaw, true) ?: [])
            : (is_array($featuresRaw) ? $featuresRaw : []);

        return [
            'workspace' => array_merge($workspace->toArray(), ['settings' => $settings]),
            'membership' => $membership->toArray(),
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'max_members' => (int) $plan->max_members,
                'max_creators' => (int) $plan->max_creators,
                'features' => $features,
            ] : null,
            'members' => $members->map(fn (WorkspaceMember $member) => $member->toArray())->values()->all(),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $base = Str::limit($base, 44, '');
        $candidate = $base;
        $suffix = 0;

        while (Workspace::query()->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = sprintf('%s-%s', $base, Str::lower(Str::random(5)));

            if ($suffix > 20) {
                $candidate = sprintf('%s-%s', $base, Str::lower(Str::random(8)));
                break;
            }
        }

        return $candidate;
    }
}
