<?php

namespace App\Http\Controllers;

use App\Mail\WorkspaceInvitationMail;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            ->get()
            ->filter(function (WorkspaceMember $membership) {
                $candidate = Workspace::query()->find($membership->workspace_id);
                return $candidate && !$this->workspaceIsDeleted($candidate);
            })
            ->values();

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
            'onboarding' => ['nullable', 'array'],
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
                    'onboarding' => $this->normalizeOnboardingSettings((array) ($validated['onboarding'] ?? [])),
                    'dataSource' => 'internal_database',
                    'legacyLegacyWorkbooksDisabled' => true,
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
        /** @var WorkspaceMember|null $currentMembership */
        $currentMembership = $request->attributes->get('workspace_membership');

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', Rule::in(['admin', 'member'])],
            'workspaceIds' => ['nullable', 'array', 'min:1'],
            'workspaceIds.*' => ['uuid'],
        ]);

        $email = Str::lower(trim((string) $validated['email']));
        $role = (string) ($validated['role'] ?? 'member');
        if (($currentMembership?->role ?? '') !== 'owner' && $role !== 'member') {
            return response()->json([
                'error' => 'Only workspace owners can invite admins.',
            ], 403);
        }

        $workspaceIds = $this->authorizedWorkspaceIdsForActor($workspace, $currentMembership, (array) ($validated['workspaceIds'] ?? [$workspace->id]));

        if (empty($workspaceIds)) {
            return response()->json(['error' => 'No valid workspaces selected for this invitation.'], 422);
        }

        $existingUser = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$this->canReserveSeatForEmail($workspace, $email, $existingUser)) {
            return response()->json([
                'error' => 'seat_limit_reached',
                'message' => 'Your current plan has no open team seats. Remove a member or upgrade before inviting another person.',
            ], 422);
        }

        $assigned = 0;
        $invited = 0;
        $affectedUserIds = [];
        $pendingInvites = [];

        DB::transaction(function () use ($workspaceIds, $email, $role, $existingUser, &$assigned, &$invited, &$affectedUserIds, &$pendingInvites, $workspace, $currentMembership) {
            foreach ($workspaceIds as $workspaceId) {
                if ($existingUser && trim((string) ($existingUser->supabase_user_id ?? '')) !== '') {
                    $targetUserId = (string) $existingUser->supabase_user_id;
                    $membership = WorkspaceMember::query()
                        ->where('workspace_id', $workspaceId)
                        ->where('user_id', $targetUserId)
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
                            'user_id' => $targetUserId,
                            'role' => $role,
                            'joined_at' => now(),
                        ]);
                    }

                    $affectedUserIds[] = $targetUserId;
                    $assigned++;
                    $this->logWorkspaceAudit($workspaceId, $currentMembership?->user_id, 'member_assigned', 'user', $targetUserId, [
                        'role' => $role,
                        'email' => $email,
                    ]);
                    continue;
                }

                $invite = $this->upsertWorkspaceInvitation($workspaceId, $email, $role);
                if ($invite) {
                    $pendingInvites[] = $invite;
                    $this->logWorkspaceAudit($workspaceId, $currentMembership?->user_id, 'invitation_created', 'workspace_invitation', (string) $invite->id, [
                        'role' => $role,
                        'email' => $email,
                    ]);
                }
                $invited++;
            }
        });

        $this->forgetWorkspaceMembershipCaches($workspaceIds, $affectedUserIds);
        foreach ($pendingInvites as $invite) {
            $this->sendWorkspaceInvitationEmail($invite);
        }

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
        /** @var WorkspaceMember|null $currentMembership */
        $currentMembership = $request->attributes->get('workspace_membership');

        $validated = $request->validate([
            'workspaceIds' => ['required', 'array'],
            'workspaceIds.*' => ['uuid'],
            'role' => ['nullable', Rule::in(['admin', 'member'])],
        ]);

        $targetUserId = trim($userId);
        if ($targetUserId === '') {
            return response()->json(['error' => 'Missing target user.'], 422);
        }

        $workspaceIds = $this->authorizedWorkspaceIdsForActor($workspace, $currentMembership, (array) $validated['workspaceIds']);
        $role = (string) ($validated['role'] ?? 'member');
        if (($currentMembership?->role ?? '') !== 'owner') {
            $targetRoles = WorkspaceMember::query()
                ->whereIn('workspace_id', $this->accountWorkspaceIds($workspace))
                ->where('user_id', $targetUserId)
                ->pluck('role')
                ->map(fn ($value) => (string) $value)
                ->all();

            if ($role !== 'member' || in_array('owner', $targetRoles, true) || in_array('admin', $targetRoles, true)) {
                return response()->json([
                    'error' => 'Only workspace owners can manage admins or account-level seats.',
                ], 403);
            }
        }

        $accountWorkspaceIds = $this->accountWorkspaceIds($workspace);
        $existingOwnerMemberships = WorkspaceMember::query()
            ->whereIn('workspace_id', $accountWorkspaceIds)
            ->where('user_id', $targetUserId)
            ->where('role', 'owner')
            ->pluck('workspace_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $finalWorkspaceIds = array_values(array_unique(array_merge($workspaceIds, $existingOwnerMemberships)));

        DB::transaction(function () use ($accountWorkspaceIds, $finalWorkspaceIds, $targetUserId, $role, $currentMembership) {
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

            foreach ($accountWorkspaceIds as $workspaceId) {
                $this->logWorkspaceAudit($workspaceId, $currentMembership?->user_id, 'member_access_updated', 'user', $targetUserId, [
                    'role' => $role,
                    'workspace_ids' => $finalWorkspaceIds,
                    'has_access' => in_array($workspaceId, $finalWorkspaceIds, true),
                ]);
            }
        });

        Cache::forget(sprintf('workspace-context:user-memberships:%s', $targetUserId));
        foreach ($accountWorkspaceIds as $accountWorkspaceId) {
            Cache::forget(sprintf('workspace-context:membership:%s:%s', $accountWorkspaceId, $targetUserId));
        }

        /** @var WorkspaceMember|null $membership */
        $membership = $request->attributes->get('workspace_membership');

        return response()->json([
            'message' => 'Workspace access updated',
            'data' => $this->workspacePayload($workspace->fresh(), $membership),
        ]);
    }

    public function cancelInvitation(Request $request, string $invitationId)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        /** @var WorkspaceMember|null $currentMembership */
        $currentMembership = $request->attributes->get('workspace_membership');

        if (!$workspace || !$currentMembership) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        if (!Schema::hasTable('workspace_invitations')) {
            return response()->json(['error' => 'Workspace invitations are not available.'], 404);
        }

        $allowedWorkspaceIds = $this->manageableWorkspaceIdsForActor($workspace, $currentMembership);
        $invitation = DB::table('workspace_invitations')
            ->where('id', $invitationId)
            ->whereNull('accepted_at')
            ->whereIn('workspace_id', $allowedWorkspaceIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->first();

        if (!$invitation) {
            return response()->json(['error' => 'Pending invitation not found.'], 404);
        }

        DB::table('workspace_invitations')->where('id', $invitationId)->delete();
        $this->logWorkspaceAudit((string) $invitation->workspace_id, $currentMembership->user_id, 'invitation_canceled', 'workspace_invitation', $invitationId, [
            'email' => (string) $invitation->email,
            'role' => (string) $invitation->role,
        ]);

        return response()->json([
            'message' => 'Invitation canceled',
            'data' => $this->workspacePayload($workspace->fresh(), $currentMembership),
        ]);
    }

    public function resendInvitation(Request $request, string $invitationId)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        /** @var WorkspaceMember|null $currentMembership */
        $currentMembership = $request->attributes->get('workspace_membership');

        if (!$workspace || !$currentMembership) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        if (!Schema::hasTable('workspace_invitations')) {
            return response()->json(['error' => 'Workspace invitations are not available.'], 404);
        }

        $allowedWorkspaceIds = $this->manageableWorkspaceIdsForActor($workspace, $currentMembership);
        $invitation = DB::table('workspace_invitations')
            ->where('id', $invitationId)
            ->whereNull('accepted_at')
            ->whereIn('workspace_id', $allowedWorkspaceIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->first();

        if (!$invitation) {
            return response()->json(['error' => 'Pending invitation not found.'], 404);
        }

        $now = now();
        $values = [
            'token' => (string) Str::uuid(),
            'expires_at' => $now->copy()->addDays(14),
        ];
        if (Schema::hasColumn('workspace_invitations', 'updated_at')) {
            $values['updated_at'] = $now;
        }

        DB::table('workspace_invitations')->where('id', $invitationId)->update($values);
        $freshInvite = DB::table('workspace_invitations')->where('id', $invitationId)->first();
        if ($freshInvite) {
            $this->sendWorkspaceInvitationEmail($freshInvite);
            $this->logWorkspaceAudit((string) $freshInvite->workspace_id, $currentMembership->user_id, 'invitation_resent', 'workspace_invitation', $invitationId, [
                'email' => (string) $freshInvite->email,
                'role' => (string) $freshInvite->role,
            ]);
        }

        return response()->json([
            'message' => 'Invitation resent',
            'data' => $this->workspacePayload($workspace->fresh(), $currentMembership),
        ]);
    }

    public function transferOwnership(Request $request)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        /** @var WorkspaceMember|null $currentMembership */
        $currentMembership = $request->attributes->get('workspace_membership');

        if (!$workspace || !$currentMembership) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        $validated = $request->validate([
            'targetUserId' => ['required', 'string', 'max:255'],
        ]);

        $targetUserId = trim((string) $validated['targetUserId']);
        if ($targetUserId === $currentMembership->user_id) {
            return response()->json(['error' => 'You are already the owner of this workspace.'], 422);
        }

        $targetMembership = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $targetUserId)
            ->first();

        if (!$targetMembership) {
            return response()->json(['error' => 'Target user must already be a member of this workspace.'], 422);
        }

        DB::transaction(function () use ($workspace, $currentMembership, $targetMembership, $targetUserId) {
            WorkspaceMember::query()
                ->where('workspace_id', $workspace->id)
                ->where('role', 'owner')
                ->where('user_id', '!=', $targetUserId)
                ->update(['role' => 'admin']);

            $targetMembership->role = 'owner';
            $targetMembership->joined_at = $targetMembership->joined_at ?: now();
            $targetMembership->save();

            $workspace->owner_id = $targetUserId;
            $workspace->save();

            $this->logWorkspaceAudit($workspace->id, $currentMembership->user_id, 'ownership_transferred', 'user', $targetUserId, [
                'previous_owner_user_id' => $currentMembership->user_id,
            ]);
        });

        Cache::forget(sprintf('workspace-context:workspace:%s', $workspace->id));
        Cache::forget(sprintf('workspace-context:user-memberships:%s', $currentMembership->user_id));
        Cache::forget(sprintf('workspace-context:user-memberships:%s', $targetUserId));
        Cache::forget(sprintf('workspace-context:membership:%s:%s', $workspace->id, $currentMembership->user_id));
        Cache::forget(sprintf('workspace-context:membership:%s:%s', $workspace->id, $targetUserId));

        $nextMembership = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $currentMembership->user_id)
            ->first();

        return response()->json([
            'message' => 'Workspace ownership transferred',
            'data' => $this->workspacePayload($workspace->fresh(), $nextMembership),
        ]);
    }

    public function archiveWorkspace(Request $request, string $workspaceId)
    {
        return $this->setWorkspaceArchived($request, $workspaceId, true);
    }

    public function restoreWorkspace(Request $request, string $workspaceId)
    {
        return $this->setWorkspaceArchived($request, $workspaceId, false);
    }

    public function deleteWorkspace(Request $request, string $workspaceId)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        /** @var WorkspaceMember|null $currentMembership */
        $currentMembership = $request->attributes->get('workspace_membership');

        if (!$workspace || !$currentMembership) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        $targetWorkspaceId = trim($workspaceId);
        $allowedWorkspaceIds = $this->accountWorkspaceIds($workspace, true);
        if (!in_array($targetWorkspaceId, $allowedWorkspaceIds, true)) {
            return response()->json(['error' => 'Workspace not available for this account.'], 404);
        }

        $targetWorkspace = Workspace::query()->find($targetWorkspaceId);
        if (!$targetWorkspace) {
            return response()->json(['error' => 'Workspace not found.'], 404);
        }

        $settings = (array) ($targetWorkspace->settings ?? []);
        if (!isset($settings['deletedAt'])) {
            $settings['deletedAt'] = now()->toIso8601String();
            $settings['deletedBy'] = $currentMembership->user_id;
            $settings['archivedAt'] = $settings['archivedAt'] ?? $settings['deletedAt'];
            $settings['archivedBy'] = $settings['archivedBy'] ?? $currentMembership->user_id;
        }

        $targetWorkspace->settings = $settings;
        $targetWorkspace->save();

        if (Schema::hasTable('workspace_invitations')) {
            DB::table('workspace_invitations')
                ->where('workspace_id', $targetWorkspace->id)
                ->whereNull('accepted_at')
                ->delete();
        }

        $affectedUserIds = WorkspaceMember::query()
            ->where('workspace_id', $targetWorkspace->id)
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        Cache::forget(sprintf('workspace-context:workspace:%s', $targetWorkspace->id));
        foreach ($affectedUserIds as $userId) {
            Cache::forget(sprintf('workspace-context:user-memberships:%s', $userId));
            Cache::forget(sprintf('workspace-context:membership:%s:%s', $targetWorkspace->id, $userId));
        }

        $this->logWorkspaceAudit($targetWorkspace->id, $currentMembership->user_id, 'workspace_deleted', 'workspace', $targetWorkspace->id, [
            'name' => $targetWorkspace->name,
            'soft_deleted' => true,
        ]);

        $fallbackWorkspaceIds = $workspace->billing_account_id
            ? DB::table('workspaces')
                ->where('billing_account_id', $workspace->billing_account_id)
                ->get(['id', 'settings'])
                ->filter(function ($row) {
                    $settings = is_string($row->settings ?? null) ? (json_decode($row->settings, true) ?: []) : ((array) ($row->settings ?? []));
                    return empty($settings['deletedAt']);
                })
                ->map(fn ($row) => (string) $row->id)
                ->all()
            : [];

        $fallbackWorkspace = Workspace::query()
            ->whereIn('id', $fallbackWorkspaceIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->orderBy('created_at')
            ->first();
        $fallbackMembership = $fallbackWorkspace
            ? WorkspaceMember::query()
                ->where('workspace_id', $fallbackWorkspace->id)
                ->where('user_id', $currentMembership->user_id)
                ->first()
            : null;

        return response()->json([
            'message' => 'Workspace deleted',
            'data' => $this->workspacePayload($fallbackWorkspace, $fallbackMembership),
        ]);
    }

    public function auditEvents(Request $request)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        /** @var WorkspaceMember|null $currentMembership */
        $currentMembership = $request->attributes->get('workspace_membership');

        if (!$workspace || !$currentMembership) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        if (!Schema::hasTable('workspace_audit_events')) {
            return response()->json(['data' => ['events' => []]]);
        }

        $workspaceIds = $currentMembership->role === 'owner'
            ? $this->accountWorkspaceIds($workspace)
            : $this->manageableWorkspaceIdsForActor($workspace, $currentMembership);

        $events = DB::table('workspace_audit_events')
            ->whereIn('workspace_id', $workspaceIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->orderByDesc('created_at')
            ->limit(min(100, max(10, (int) $request->query('limit', 40))))
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'workspace_id' => (string) $row->workspace_id,
                'actor_user_id' => (string) ($row->actor_user_id ?? ''),
                'event_type' => (string) $row->event_type,
                'subject_type' => (string) ($row->subject_type ?? ''),
                'subject_id' => (string) ($row->subject_id ?? ''),
                'metadata' => is_string($row->metadata ?? null) ? (json_decode($row->metadata, true) ?: []) : ((array) ($row->metadata ?? [])),
                'created_at' => (string) $row->created_at,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'events' => $events,
            ],
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
        if ($currentMembership->role !== 'owner' && $member->role !== 'member') {
            return response()->json(['error' => 'Only workspace owners can remove admins.'], 403);
        }

        $member->delete();
        Cache::forget(sprintf('workspace-context:user-memberships:%s', $member->user_id));
        Cache::forget(sprintf('workspace-context:membership:%s:%s', $workspace->id, $member->user_id));
        $this->logWorkspaceAudit($workspace->id, $currentMembership->user_id, 'member_removed', 'user', $member->user_id, [
            'membership_id' => $member->id,
            'role' => $member->role,
        ]);

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
            'legacyLegacyWorkbooksDisabled',
        ];

        $incoming = array_diff_key((array) $validated['settings'], array_flip($protectedKeys));
        $settings = array_merge((array) ($workspace->settings ?? []), $incoming, [
            'workspaceDataKey' => (string) data_get($workspace->settings, 'workspaceDataKey', 'workspace:' . $workspace->slug),
            'workbookId' => (string) data_get($workspace->settings, 'workbookId', data_get($workspace->settings, 'workspaceDataKey', 'workspace:' . $workspace->slug)),
            'dataSource' => 'internal_database',
            'legacyLegacyWorkbooksDisabled' => true,
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

    public function updateCurrent(Request $request)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        /** @var WorkspaceMember|null $membership */
        $membership = $request->attributes->get('workspace_membership');

        if (!$workspace || !$membership) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $workspace->name = trim((string) $validated['name']);
        $workspace->save();

        Cache::forget(sprintf('workspace-context:workspace:%s', $workspace->id));
        $this->logWorkspaceAudit($workspace->id, $membership->user_id, 'workspace_renamed', 'workspace', $workspace->id, [
            'name' => $workspace->name,
        ]);

        return response()->json([
            'message' => 'Workspace updated',
            'data' => $this->workspacePayload($workspace->fresh(), $membership),
        ]);
    }

    private function setWorkspaceArchived(Request $request, string $workspaceId, bool $archived)
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        /** @var WorkspaceMember|null $currentMembership */
        $currentMembership = $request->attributes->get('workspace_membership');

        if (!$workspace || !$currentMembership) {
            return response()->json(['error' => 'Missing workspace context.'], 400);
        }

        $targetWorkspaceId = trim($workspaceId);
        $allowedWorkspaceIds = $this->accountWorkspaceIds($workspace);
        if (!in_array($targetWorkspaceId, $allowedWorkspaceIds, true)) {
            return response()->json(['error' => 'Workspace not available for this account.'], 404);
        }

        $targetWorkspace = Workspace::query()->find($targetWorkspaceId);
        if (!$targetWorkspace) {
            return response()->json(['error' => 'Workspace not found.'], 404);
        }

        $settings = (array) ($targetWorkspace->settings ?? []);
        if ($archived) {
            $settings['archivedAt'] = now()->toIso8601String();
            $settings['archivedBy'] = $currentMembership->user_id;
        } else {
            unset($settings['archivedAt'], $settings['archivedBy']);
        }

        $targetWorkspace->settings = $settings;
        $targetWorkspace->save();
        Cache::forget(sprintf('workspace-context:workspace:%s', $targetWorkspace->id));

        $this->logWorkspaceAudit($targetWorkspace->id, $currentMembership->user_id, $archived ? 'workspace_archived' : 'workspace_restored', 'workspace', $targetWorkspace->id, [
            'name' => $targetWorkspace->name,
        ]);

        return response()->json([
            'message' => $archived ? 'Workspace archived' : 'Workspace restored',
            'data' => $this->workspacePayload($workspace->fresh(), $currentMembership),
        ]);
    }

    private function accountWorkspaceIds(Workspace $workspace, bool $includeDeleted = false): array
    {
        $billingAccountId = trim((string) ($workspace->billing_account_id ?? ''));

        if ($billingAccountId === '') {
            return $includeDeleted || !$this->workspaceIsDeleted($workspace) ? [(string) $workspace->id] : [];
        }

        return DB::table('workspaces')
            ->where('billing_account_id', $billingAccountId)
            ->get(['id', 'settings'])
            ->filter(function ($row) use ($includeDeleted) {
                if ($includeDeleted) {
                    return true;
                }

                $settings = is_string($row->settings ?? null) ? (json_decode($row->settings, true) ?: []) : ((array) ($row->settings ?? []));
                return empty($settings['deletedAt']);
            })
            ->map(fn ($row) => (string) $row->id)
            ->all();
    }

    private function workspaceIsDeleted(Workspace $workspace): bool
    {
        $settings = (array) ($workspace->settings ?? []);
        return !empty($settings['deletedAt']);
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

    private function manageableWorkspaceIdsForActor(Workspace $workspace, ?WorkspaceMember $membership): array
    {
        if (!$membership) {
            return [];
        }

        $accountWorkspaceIds = $this->accountWorkspaceIds($workspace);
        if ($membership->role === 'owner') {
            return $accountWorkspaceIds;
        }

        return WorkspaceMember::query()
            ->whereIn('workspace_id', $accountWorkspaceIds)
            ->where('user_id', $membership->user_id)
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('workspace_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    private function authorizedWorkspaceIdsForActor(Workspace $workspace, ?WorkspaceMember $membership, array $requestedWorkspaceIds): array
    {
        $allowed = array_flip($this->manageableWorkspaceIdsForActor($workspace, $membership));

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($id) => trim((string) $id),
            $requestedWorkspaceIds ?: [(string) $workspace->id]
        ))));

        return array_values(array_filter($ids, fn ($id) => isset($allowed[$id])));
    }

    private function canReserveSeatForEmail(Workspace $workspace, string $email, ?object $existingUser): bool
    {
        $accountWorkspaceIds = $this->accountWorkspaceIds($workspace);
        if (empty($accountWorkspaceIds)) {
            return false;
        }

        $targetUserId = trim((string) ($existingUser->supabase_user_id ?? ''));
        if ($targetUserId !== '' && WorkspaceMember::query()->whereIn('workspace_id', $accountWorkspaceIds)->where('user_id', $targetUserId)->exists()) {
            return true;
        }

        $billingAccount = $workspace->billing_account_id
            ? DB::table('billing_accounts')->where('id', $workspace->billing_account_id)->first()
            : null;
        $planId = (string) ($billingAccount->plan_id ?? $workspace->plan_id ?? 'free');
        $maxMembers = (int) (DB::table('plans')->where('id', $planId ?: 'free')->value('max_members') ?: 1);

        $activeSeats = WorkspaceMember::query()
            ->whereIn('workspace_id', $accountWorkspaceIds)
            ->distinct('user_id')
            ->count('user_id');

        $pendingEmails = Schema::hasTable('workspace_invitations')
            ? DB::table('workspace_invitations')
                ->whereIn('workspace_id', $accountWorkspaceIds)
                ->whereNull('accepted_at')
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->pluck('email')
                ->map(fn ($value) => Str::lower(trim((string) $value)))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];

        $pendingCount = count(array_filter($pendingEmails, fn ($pendingEmail) => $pendingEmail !== $email));

        return ($activeSeats + $pendingCount) < max(1, $maxMembers);
    }

    private function forgetWorkspaceMembershipCaches(array $workspaceIds, array $userIds): void
    {
        foreach (array_unique(array_filter($userIds)) as $userId) {
            Cache::forget(sprintf('workspace-context:user-memberships:%s', $userId));
            foreach (array_unique($workspaceIds) as $workspaceId) {
                Cache::forget(sprintf('workspace-context:membership:%s:%s', $workspaceId, $userId));
            }
        }
    }

    private function upsertWorkspaceInvitation(string $workspaceId, string $email, string $role): ?object
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
            return DB::table('workspace_invitations')->where('id', $existing->id)->first();
        }

        $insert = array_merge($match, $values, [
            'id' => (string) Str::uuid(),
        ]);

        if (Schema::hasColumn('workspace_invitations', 'created_at')) {
            $insert['created_at'] = $now;
        }

        DB::table('workspace_invitations')->insert($insert);

        return DB::table('workspace_invitations')->where('id', $insert['id'])->first();
    }

    private function sendWorkspaceInvitationEmail(object $invite): void
    {
        $workspace = Workspace::query()->find((string) $invite->workspace_id);
        if (!$workspace) {
            return;
        }

        $email = Str::lower(trim((string) $invite->email));
        if ($email === '') {
            return;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $inviteUrl = $frontendUrl . '/auth?mode=signup&email=' . urlencode($email);

        try {
            Mail::to($email)->send(new WorkspaceInvitationMail(
                workspaceName: (string) $workspace->name,
                role: (string) $invite->role,
                inviteUrl: $inviteUrl,
                expiresAt: $invite->expires_at ? (string) $invite->expires_at : 'soon'
            ));
        } catch (\Throwable $exception) {
            Log::warning('workspace invitation email failed', [
                'workspace_id' => (string) $invite->workspace_id,
                'invitation_id' => (string) $invite->id,
                'email_hash' => sha1($email),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function logWorkspaceAudit(string $workspaceId, ?string $actorUserId, string $eventType, ?string $subjectType = null, ?string $subjectId = null, array $metadata = []): void
    {
        if (!Schema::hasTable('workspace_audit_events')) {
            return;
        }

        DB::table('workspace_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
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
        $settings['legacyLegacyWorkbooksDisabled'] = true;

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
            ->filter(fn ($row) => empty($row['settings']['deletedAt']))
            ->values()
            ->all();

        $accountWorkspaceIdsForBilling = $this->accountWorkspaceIds($workspace);
        $visibleWorkspaceIds = $membership->role === 'owner'
            ? $accountWorkspaceIdsForBilling
            : array_values(array_filter(array_map(fn ($item) => (string) ($item['id'] ?? ''), $userWorkspaces)));

        $accountWorkspaceRows = empty($visibleWorkspaceIds)
            ? collect()
            : DB::table('workspaces')->whereIn('id', $visibleWorkspaceIds)->orderBy('created_at')->get();

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
        $teamVisibleWorkspaceIds = $membership->role === 'owner'
            ? $accountWorkspaceIdsForBilling
            : $this->manageableWorkspaceIdsForActor($workspace, $membership);

        $accountMemberRows = empty($teamVisibleWorkspaceIds)
            ? collect()
            : WorkspaceMember::query()
                ->whereIn('workspace_id', $teamVisibleWorkspaceIds)
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

        $pendingInvitations = empty($teamVisibleWorkspaceIds) || !Schema::hasTable('workspace_invitations')
            ? []
            : DB::table('workspace_invitations')
                ->whereIn('workspace_id', $teamVisibleWorkspaceIds)
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

    private function normalizeOnboardingSettings(array $input): ?array
    {
        if (empty($input)) {
            return null;
        }

        $workspaceType = in_array(($input['workspaceType'] ?? ''), ['agency', 'brand', 'client'], true)
            ? (string) $input['workspaceType']
            : null;
        $primaryGoal = in_array(($input['primaryGoal'] ?? ''), ['discover', 'import', 'followups'], true)
            ? (string) $input['primaryGoal']
            : null;
        $platformFocus = in_array(($input['platformFocus'] ?? ''), ['instagram', 'tiktok', 'both'], true)
            ? (string) $input['platformFocus']
            : null;
        $firstAction = in_array(($input['firstAction'] ?? ''), ['discover', 'crm', 'team'], true)
            ? (string) $input['firstAction']
            : null;

        $completed = [];
        $completedInput = is_array($input['completed'] ?? null) ? $input['completed'] : [];
        foreach (['workspaceCreated', 'brandContext', 'firstDiscovery', 'firstCreators', 'firstOutreach', 'taskQueue', 'teamInvited'] as $key) {
            if (array_key_exists($key, $completedInput)) {
                $completed[$key] = (bool) $completedInput[$key];
            }
        }

        return array_filter([
            'version' => 1,
            'createdAt' => isset($input['createdAt']) ? Str::limit((string) $input['createdAt'], 64, '') : null,
            'updatedAt' => isset($input['updatedAt']) ? Str::limit((string) $input['updatedAt'], 64, '') : null,
            'workspaceType' => $workspaceType,
            'primaryGoal' => $primaryGoal,
            'platformFocus' => $platformFocus,
            'monthlyCreatorTarget' => isset($input['monthlyCreatorTarget']) ? max(1, min(100000, (int) $input['monthlyCreatorTarget'])) : null,
            'teamSize' => isset($input['teamSize']) ? max(1, min(1000, (int) $input['teamSize'])) : null,
            'firstAction' => $firstAction,
            'completed' => $completed,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
