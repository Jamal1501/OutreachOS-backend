<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        if ($requestedWorkspaceId !== '' && !$memberships->firstWhere('workspace_id', $requestedWorkspaceId)) {
            return response()->json([
                'error' => 'workspace_not_available',
                'message' => 'Requested workspace is not available for this user.',
            ], 403);
        }

        $membership = $requestedWorkspaceId !== ''
            ? $memberships->firstWhere('workspace_id', $requestedWorkspaceId)
            : $memberships->first();

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
            $billingAccount = $this->ensureBillingAccountForOwner($supabaseUserId, $name, $workspaceId);

            $workspace = Workspace::query()->create([
                'id' => $workspaceId,
                'billing_account_id' => $billingAccount->id,
                'name' => $name,
                'slug' => $slug,
                'owner_id' => $supabaseUserId,
                'plan_id' => $billingAccount->plan_id ?: 'free',
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
            'workspaceIds' => ['nullable', 'array', 'min:1'],
            'workspaceIds.*' => ['uuid'],
        ]);

        $email = Str::lower(trim((string) $validated['email']));
        $role = (string) ($validated['role'] ?? 'member');
        $workspaceIds = $this->authorizedWorkspaceIdsForAccount($workspace, (array) ($validated['workspaceIds'] ?? [$workspace->id]));

        if (empty($workspaceIds)) {
            return response()->json(['error' => 'No valid workspaces selected for this invitation.'], 422);
        }

        $existingUser = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
        $assigned = 0;
        $invited = 0;

        DB::transaction(function () use ($workspaceIds, $email, $role, $existingUser, &$assigned, &$invited) {
            foreach ($workspaceIds as $workspaceId) {
                if ($existingUser && trim((string) ($existingUser->supabase_user_id ?? '')) !== '') {
                    $membership = WorkspaceMember::query()
                        ->where('workspace_id', $workspaceId)
                        ->where('user_id', (string) $existingUser->supabase_user_id)
                        ->first();

                    if ($membership) {
                        if ($membership->role !== 'owner') {
                            $membership->role = $role;
                            $membership->joined_at = $membership->joined_at ?: now();
                            $membership->save();
                        }
                    } else {
                        WorkspaceMember::query()->create([
                            'id' => (string) Str::uuid(),
                            'workspace_id' => $workspaceId,
                            'user_id' => (string) $existingUser->supabase_user_id,
                            'role' => $role,
                            'joined_at' => now(),
                        ]);
                    }

                    $assigned++;
                    continue;
                }

                $this->upsertWorkspaceInvitation($workspaceId, $email, $role);
                $invited++;
            }
        });

        return response()->json([
            'message' => $assigned > 0 ? 'Workspace access assigned' : 'Invitation created',
            'data' => [
                'email' => $email,
                'role' => $role,
                'workspaceIds' => $workspaceIds,
                'assignedWorkspaces' => $assigned,
                'pendingInvitations' => $invited,
            ],
        ], 201);
    }

    public function updateMemberWorkspaces(Request $request, string $userId)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        if (!$workspace) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        $validated = $request->validate([
            'workspaceIds' => ['required', 'array'],
            'workspaceIds.*' => ['uuid'],
            'role' => ['nullable', Rule::in(['admin', 'member'])],
        ]);

        $targetUserId = trim($userId);
        if ($targetUserId === '') {
            return response()->json(['error' => 'Missing target user.'], 422);
        }

        $workspaceIds = $this->authorizedWorkspaceIdsForAccount($workspace, (array) $validated['workspaceIds']);
        $role = (string) ($validated['role'] ?? 'member');

        $accountWorkspaceIds = $this->accountWorkspaceIds($workspace);
        $existingOwnerMemberships = WorkspaceMember::query()
            ->whereIn('workspace_id', $accountWorkspaceIds)
            ->where('user_id', $targetUserId)
            ->where('role', 'owner')
            ->pluck('workspace_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $finalWorkspaceIds = array_values(array_unique(array_merge($workspaceIds, $existingOwnerMemberships)));

        DB::transaction(function () use ($accountWorkspaceIds, $finalWorkspaceIds, $targetUserId, $role) {
            WorkspaceMember::query()
                ->whereIn('workspace_id', $accountWorkspaceIds)
                ->where('user_id', $targetUserId)
                ->where('role', '!=', 'owner')
                ->whereNotIn('workspace_id', $finalWorkspaceIds ?: ['00000000-0000-0000-0000-000000000000'])
                ->delete();

            foreach ($finalWorkspaceIds as $workspaceId) {
                $existing = WorkspaceMember::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('user_id', $targetUserId)
                    ->first();

                if ($existing) {
                    if ($existing->role !== 'owner') {
                        $existing->role = $role;
                        $existing->joined_at = $existing->joined_at ?: now();
                        $existing->save();
                    }
                    continue;
                }

                WorkspaceMember::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'user_id' => $targetUserId,
                    'role' => $role,
                    'joined_at' => now(),
                ]);
            }
        });

        /** @var WorkspaceMember|null $membership */
        $membership = $request->attributes->get('workspace_membership');

        return response()->json([
            'message' => 'Workspace access updated',
            'data' => $this->workspacePayload($workspace->fresh(), $membership),
        ]);
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

    private function accountWorkspaceIds(Workspace $workspace): array
    {
        $billingAccountId = trim((string) ($workspace->billing_account_id ?? ''));

        if ($billingAccountId === '') {
            return [(string) $workspace->id];
        }

        return DB::table('workspaces')
            ->where('billing_account_id', $billingAccountId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    private function authorizedWorkspaceIdsForAccount(Workspace $workspace, array $requestedWorkspaceIds): array
    {
        $accountWorkspaceIds = $this->accountWorkspaceIds($workspace);
        $allowed = array_flip($accountWorkspaceIds);

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($id) => trim((string) $id),
            $requestedWorkspaceIds ?: [(string) $workspace->id]
        ))));

        return array_values(array_filter($ids, fn ($id) => isset($allowed[$id])));
    }

    private function upsertWorkspaceInvitation(string $workspaceId, string $email, string $role): void
    {
        $now = now();
        $match = [
            'workspace_id' => $workspaceId,
            'email' => $email,
            'accepted_at' => null,
        ];

        $values = [
            'role' => $role,
            'token' => (string) Str::uuid(),
            'expires_at' => $now->copy()->addDays(14),
        ];

        if (Schema::hasColumn('workspace_invitations', 'updated_at')) {
            $values['updated_at'] = $now;
        }

        $existing = DB::table('workspace_invitations')->where($match)->first();
        if ($existing) {
            DB::table('workspace_invitations')->where('id', $existing->id)->update($values);
            return;
        }

        $insert = array_merge($match, $values, [
            'id' => (string) Str::uuid(),
        ]);

        if (Schema::hasColumn('workspace_invitations', 'created_at')) {
            $insert['created_at'] = $now;
        }

        DB::table('workspace_invitations')->insert($insert);
    }

    private function workspacePayload(?Workspace $workspace, ?WorkspaceMember $membership): array
    {
        if (!$workspace || !$membership) {
            return [
                'workspace' => null,
                'membership' => null,
                'plan' => null,
                'members' => [],
                'workspaces' => [],
                'accountWorkspaces' => [],
                'accountMembers' => [],
                'pendingInvitations' => [],
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

        $billingAccount = $workspace->billing_account_id
            ? DB::table('billing_accounts')->where('id', $workspace->billing_account_id)->first()
            : null;
        $effectivePlanId = $billingAccount?->plan_id ?: ($workspace->plan_id ?: 'free');
        $plan = DB::table('plans')->where('id', $effectivePlanId)->first();
        $members = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->orderByRaw("CASE role WHEN 'owner' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END")
            ->orderBy('joined_at')
            ->get();

        $featuresRaw = $plan->features ?? [];
        $features = is_string($featuresRaw)
            ? (json_decode($featuresRaw, true) ?: [])
            : (is_array($featuresRaw) ? $featuresRaw : []);

        $userWorkspaces = WorkspaceMember::query()
            ->where('user_id', $membership->user_id)
            ->join('workspaces', 'workspaces.id', '=', 'workspace_members.workspace_id')
            ->orderBy('workspace_members.joined_at')
            ->get([
                'workspaces.id',
                'workspaces.name',
                'workspaces.slug',
                'workspaces.owner_id',
                'workspaces.billing_account_id',
                'workspaces.plan_id',
                'workspaces.settings',
                'workspaces.created_at',
                'workspace_members.role',
            ])
            ->map(function ($row) {
                $settings = is_string($row->settings) ? (json_decode($row->settings, true) ?: []) : ((array) $row->settings);
                return [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'slug' => (string) $row->slug,
                    'owner_id' => (string) $row->owner_id,
                    'billing_account_id' => (string) ($row->billing_account_id ?? ''),
                    'plan_id' => (string) ($row->plan_id ?? 'free'),
                    'settings' => $settings,
                    'created_at' => (string) $row->created_at,
                    'role' => (string) $row->role,
                ];
            })
            ->values()
            ->all();

        $accountWorkspaceRows = $workspace->billing_account_id
            ? DB::table('workspaces')->where('billing_account_id', $workspace->billing_account_id)->orderBy('created_at')->get()
            : collect([$workspace]);

        $accountWorkspaces = $accountWorkspaceRows->map(function ($row) {
            $rowSettings = is_string($row->settings ?? null) ? (json_decode($row->settings, true) ?: []) : ((array) ($row->settings ?? []));
            return [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'owner_id' => (string) ($row->owner_id ?? ''),
                'billing_account_id' => (string) ($row->billing_account_id ?? ''),
                'plan_id' => (string) ($row->plan_id ?? 'free'),
                'settings' => $rowSettings,
                'created_at' => (string) ($row->created_at ?? ''),
            ];
        })->values()->all();

        $accountWorkspaceIds = array_map(fn ($item) => (string) $item['id'], $accountWorkspaces);

        $accountMemberRows = empty($accountWorkspaceIds)
            ? collect()
            : WorkspaceMember::query()
                ->whereIn('workspace_id', $accountWorkspaceIds)
                ->leftJoin('users', function ($join) {
                    $join->on(DB::raw('users.supabase_user_id::text'), '=', DB::raw('workspace_members.user_id::text'));
                })
                ->get([
                    'workspace_members.id',
                    'workspace_members.workspace_id',
                    'workspace_members.user_id',
                    'workspace_members.role',
                    'workspace_members.joined_at',
                    'users.email',
                    'users.name',
                ]);

        $accountMembers = $accountMemberRows
            ->groupBy('user_id')
            ->map(function ($rows, $userId) {
                $first = $rows->first();
                $roles = $rows->pluck('role')->map(fn ($role) => (string) $role)->all();
                $primaryRole = in_array('owner', $roles, true) ? 'owner' : (in_array('admin', $roles, true) ? 'admin' : 'member');

                return [
                    'user_id' => (string) $userId,
                    'email' => (string) ($first->email ?? ''),
                    'name' => (string) ($first->name ?? ''),
                    'role' => $primaryRole,
                    'workspaceIds' => $rows->pluck('workspace_id')->map(fn ($id) => (string) $id)->unique()->values()->all(),
                    'memberships' => $rows->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'workspace_id' => (string) $row->workspace_id,
                        'role' => (string) $row->role,
                        'joined_at' => (string) $row->joined_at,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        $pendingInvitations = empty($accountWorkspaceIds) || !Schema::hasTable('workspace_invitations')
            ? []
            : DB::table('workspace_invitations')
                ->whereIn('workspace_id', $accountWorkspaceIds)
                ->whereNull('accepted_at')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'workspace_id' => (string) $row->workspace_id,
                    'email' => (string) $row->email,
                    'role' => (string) $row->role,
                    'expires_at' => (string) $row->expires_at,
                    'created_at' => (string) ($row->created_at ?? ''),
                ])
                ->values()
                ->all();

        return [
            'workspace' => array_merge($workspace->toArray(), ['settings' => $settings]),
            'billingAccount' => $billingAccount ? [
                'id' => $billingAccount->id,
                'name' => $billingAccount->name,
                'ownerUserId' => $billingAccount->owner_user_id,
                'primaryWorkspaceId' => $billingAccount->primary_workspace_id,
                'planId' => $effectivePlanId,
                'billingScope' => 'shared_account',
            ] : null,
            'workspaces' => $userWorkspaces,
            'accountWorkspaces' => $accountWorkspaces,
            'accountMembers' => $accountMembers,
            'pendingInvitations' => $pendingInvitations,
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

    private function ensureBillingAccountForOwner(string $ownerUserId, string $workspaceName, string $workspaceId): object
    {
        $account = DB::table('billing_accounts')
            ->where('owner_user_id', $ownerUserId)
            ->lockForUpdate()
            ->first();

        if ($account) {
            return $account;
        }

        $accountId = (string) Str::uuid();
        DB::table('billing_accounts')->insert([
            'id' => $accountId,
            'owner_user_id' => $ownerUserId,
            'primary_workspace_id' => $workspaceId,
            'name' => $workspaceName . ' billing',
            'plan_id' => 'free',
            'status' => 'active',
            'metadata' => json_encode([
                'bootstrap' => true,
                'free_welcome_credits_account_scoped' => true,
                'created_from_workspace_id' => $workspaceId,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('billing_accounts')->where('id', $accountId)->lockForUpdate()->first();
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
