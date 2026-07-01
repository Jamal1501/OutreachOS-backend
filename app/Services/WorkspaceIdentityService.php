<?php

namespace App\Services;

use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WorkspaceIdentityService
{
    public function canonicalUserId(Request $request): string
    {
        $user = $request->user();

        return trim((string) (
            $request->attributes->get('supabase_user_id')
            ?: $user?->supabase_user_id
            ?: ''
        ));
    }

    public function userIdCandidates(Request $request): array
    {
        $user = $request->user();
        $supabaseUser = (array) $request->attributes->get('supabase_user', []);
        $email = strtolower(trim((string) ($user?->email ?: ($supabaseUser['email'] ?? ''))));

        $candidates = [
            $this->canonicalUserId($request),
            (string) ($request->attributes->get('auth_user_id') ?: ''),
            (string) ($user?->getKey() ?: ''),
            (string) ($user?->supabase_user_id ?: ''),
        ];

        if ($email !== '') {
            $matchedUsers = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->get(['id', 'supabase_user_id']);

            foreach ($matchedUsers as $matchedUser) {
                $candidates[] = (string) ($matchedUser->id ?? '');
                $candidates[] = (string) ($matchedUser->supabase_user_id ?? '');
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($candidate) => trim((string) $candidate),
            $candidates,
        ), fn ($candidate) => $candidate !== '')));
    }

    public function recoverLegacyMemberships(Request $request): void
    {
        $canonicalUserId = $this->canonicalUserId($request);
        if ($canonicalUserId === '') {
            return;
        }

        $legacyIds = array_values(array_filter(
            $this->userIdCandidates($request),
            fn (string $candidate) => $candidate !== $canonicalUserId,
        ));

        if ($legacyIds === []) {
            return;
        }

        DB::transaction(function () use ($canonicalUserId, $legacyIds): void {
            $legacyOwnedWorkspaces = DB::table('workspaces')
                ->whereIn('owner_id', $legacyIds)
                ->lockForUpdate()
                ->get(['id']);

            foreach ($legacyOwnedWorkspaces as $workspace) {
                $workspaceId = (string) $workspace->id;
                $membership = WorkspaceMember::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('user_id', $canonicalUserId)
                    ->lockForUpdate()
                    ->first();

                if (!$membership) {
                    WorkspaceMember::query()->create([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $workspaceId,
                        'user_id' => $canonicalUserId,
                        'role' => 'owner',
                        'joined_at' => now(),
                    ]);
                } elseif ($membership->role !== 'owner') {
                    $membership->role = 'owner';
                    $membership->joined_at = $membership->joined_at ?: now();
                    $membership->save();
                }
            }

            DB::table('workspaces')
                ->whereIn('owner_id', $legacyIds)
                ->update(['owner_id' => $canonicalUserId, 'updated_at' => now()]);

            if (Schema::hasTable('billing_accounts')) {
                DB::table('billing_accounts')
                    ->whereIn('owner_user_id', $legacyIds)
                    ->update(['owner_user_id' => $canonicalUserId, 'updated_at' => now()]);
            }

            $legacyMemberships = WorkspaceMember::query()
                ->whereIn('user_id', $legacyIds)
                ->lockForUpdate()
                ->get();

            foreach ($legacyMemberships as $legacyMembership) {
                $existingCanonical = WorkspaceMember::query()
                    ->where('workspace_id', $legacyMembership->workspace_id)
                    ->where('user_id', $canonicalUserId)
                    ->lockForUpdate()
                    ->first();

                if ($existingCanonical) {
                    if ($this->roleRank((string) $legacyMembership->role) > $this->roleRank((string) $existingCanonical->role)) {
                        $existingCanonical->role = $legacyMembership->role;
                        $existingCanonical->joined_at = $existingCanonical->joined_at ?: $legacyMembership->joined_at;
                        $existingCanonical->save();
                    }

                    $legacyMembership->delete();
                    continue;
                }

                $legacyMembership->user_id = $canonicalUserId;
                $legacyMembership->save();
            }
        });

        $this->forgetMembershipCaches($canonicalUserId, $legacyIds);
    }

    private function roleRank(string $role): int
    {
        return match ($role) {
            'owner' => 3,
            'admin' => 2,
            default => 1,
        };
    }

    private function forgetMembershipCaches(string $canonicalUserId, array $legacyIds): void
    {
        foreach (array_merge([$canonicalUserId], $legacyIds) as $userId) {
            Cache::forget(sprintf('workspace-context:user-memberships:%s', $userId));
        }
    }
}
