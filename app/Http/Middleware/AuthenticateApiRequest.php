<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = trim((string) $request->bearerToken());

        if ($bearerToken !== '') {
            $supabaseUser = $this->resolveSupabaseUser($bearerToken);
            if (! $supabaseUser) {
                return response()->json([
                    'error' => 'Invalid or expired authentication token.',
                ], 401);
            }

            $user = $this->syncLocalUser($supabaseUser);
            Auth::setUser($user);
            $request->setUserResolver(static fn () => $user);
            $request->attributes->set('auth_user_id', $user->getKey());
            $request->attributes->set('supabase_user_id', $user->supabase_user_id);
            $request->attributes->set('supabase_user', $supabaseUser);

            $this->acceptPendingWorkspaceInvitations($user);

            $accountDeletionPending = Schema::hasTable('data_deletion_requests')
                && DB::table('data_deletion_requests')
                    ->where('type', 'account')
                    ->where('user_id', $user->supabase_user_id)
                    ->where('status', 'scheduled')
                    ->exists();
            if ($accountDeletionPending && ! $request->is('api/account/restore')) {
                return response()->json([
                    'error' => 'account_deletion_pending',
                    'message' => 'This account is scheduled for deletion. Restore it before continuing.',
                ], 403);
            }

            if (config('outreach.launch.require_verified_email', true) && ! $user->email_verified_at) {
                return response()->json([
                    'error' => 'email_verification_required',
                    'message' => 'Verify your email address before accessing the pilot.',
                ], 403);
            }

            if (config('outreach.launch.invite_only', false) && ! $this->launchAccessAllowed($user)) {
                return response()->json([
                    'error' => 'pilot_invitation_required',
                    'message' => 'SocialCore is currently invite-only. Ask the pilot administrator for access.',
                ], 403);
            }

            return $next($request);
        }

        $legacyKey = trim((string) $request->header('X-APP-KEY'));
        $configuredKey = trim((string) config('services.app_security.key'));
        $allowLegacy = (bool) config('services.app_security.allow_legacy_key', false);

        if ($allowLegacy && $configuredKey !== '' && hash_equals($configuredKey, $legacyKey)) {
            $request->attributes->set('legacy_api_access', true);

            return $next($request);
        }

        return response()->json([
            'error' => 'Authentication required.',
        ], 401);
    }

    private function launchAccessAllowed(User $user): bool
    {
        if (WorkspaceMember::query()->where('user_id', $user->supabase_user_id)->exists()) {
            return true;
        }

        $email = Str::lower(trim((string) $user->email));
        $allowedEmails = array_map(fn ($value) => Str::lower((string) $value), (array) config('outreach.launch.allowed_emails', []));
        if (in_array($email, $allowedEmails, true)) {
            return true;
        }

        $domain = Str::afterLast($email, '@');
        $allowedDomains = array_map(fn ($value) => Str::lower(ltrim((string) $value, '@')), (array) config('outreach.launch.allowed_domains', []));

        return $domain !== '' && in_array($domain, $allowedDomains, true);
    }

    private function resolveSupabaseUser(string $bearerToken): ?array
    {
        $supabaseUrl = rtrim((string) config('services.supabase.url'), '/');
        $supabaseApiKey = trim((string) (config('services.supabase.service_role_key') ?: config('services.supabase.anon_key')));

        if ($supabaseUrl === '' || $supabaseApiKey === '') {
            return null;
        }

        $cacheKey = 'supabase:user:'.sha1($bearerToken);

        return Cache::remember($cacheKey, now()->addSeconds(90), function () use ($supabaseUrl, $supabaseApiKey, $bearerToken) {
            $response = Http::timeout((int) config('services.supabase.auth_timeout', 15))
                ->acceptJson()
                ->withHeaders([
                    'apikey' => $supabaseApiKey,
                    'Authorization' => 'Bearer '.$bearerToken,
                ])
                ->get($supabaseUrl.'/auth/v1/user');

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();

            return is_array($payload) ? $payload : null;
        });
    }

    private function syncLocalUser(array $supabaseUser): User
    {
        $supabaseUserId = trim((string) ($supabaseUser['id'] ?? ''));
        $email = trim((string) ($supabaseUser['email'] ?? ''));
        $userMetadata = (array) ($supabaseUser['user_metadata'] ?? []);
        $fullName = trim((string) ($userMetadata['full_name'] ?? $userMetadata['name'] ?? ''));
        $displayName = $fullName !== ''
            ? $fullName
            : ($email !== '' ? Str::before($email, '@') : 'Workspace User');

        $user = User::query()
            ->where(function ($query) use ($supabaseUserId, $email) {
                if ($supabaseUserId !== '') {
                    $query->where('supabase_user_id', $supabaseUserId);
                }

                if ($email !== '') {
                    if ($supabaseUserId !== '') {
                        $query->orWhere('email', $email);
                    } else {
                        $query->where('email', $email);
                    }
                }
            })
            ->first();

        if (! $user) {
            $user = new User;
            $user->password = Str::random(32);
        }

        $user->supabase_user_id = $supabaseUserId !== '' ? $supabaseUserId : $user->supabase_user_id;
        $user->email = $email !== '' ? $email : ($user->email ?: 'missing-email@example.invalid');
        $user->name = $displayName;
        $user->email_verified_at = ! empty($supabaseUser['email_confirmed_at']) ? now() : $user->email_verified_at;
        $user->save();

        return $user;
    }

    private function acceptPendingWorkspaceInvitations(User $user): void
    {
        if (! Schema::hasTable('workspace_invitations')) {
            return;
        }

        $email = Str::lower(trim((string) $user->email));
        $supabaseUserId = trim((string) $user->supabase_user_id);

        if ($email === '' || $supabaseUserId === '') {
            return;
        }

        $acceptedWorkspaceIds = [];

        DB::transaction(function () use ($email, $supabaseUserId, &$acceptedWorkspaceIds) {
            $invitations = DB::table('workspace_invitations')
                ->join('workspaces', 'workspaces.id', '=', 'workspace_invitations.workspace_id')
                ->whereRaw('LOWER(workspace_invitations.email) = ?', [$email])
                ->whereNull('workspace_invitations.accepted_at')
                ->where(function ($query) {
                    $query->whereNull('workspace_invitations.expires_at')
                        ->orWhere('workspace_invitations.expires_at', '>', now());
                })
                ->lockForUpdate()
                ->get([
                    'workspace_invitations.id',
                    'workspace_invitations.workspace_id',
                    'workspace_invitations.role',
                    'workspaces.billing_account_id',
                    'workspaces.plan_id',
                ]);

            foreach ($invitations as $invite) {
                $workspaceId = (string) $invite->workspace_id;
                $role = in_array((string) $invite->role, ['admin', 'member'], true)
                    ? (string) $invite->role
                    : 'member';

                $membership = WorkspaceMember::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('user_id', $supabaseUserId)
                    ->first();

                if ($membership) {
                    if ($membership->role !== 'owner') {
                        $membership->role = $role;
                        $membership->joined_at = $membership->joined_at ?: now();
                        $membership->save();
                    }
                } elseif ($this->canAddSeatToWorkspace($invite, $supabaseUserId)) {
                    WorkspaceMember::query()->create([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $workspaceId,
                        'user_id' => $supabaseUserId,
                        'role' => $role,
                        'joined_at' => now(),
                    ]);
                } else {
                    continue;
                }

                $update = ['accepted_at' => now()];
                if (Schema::hasColumn('workspace_invitations', 'updated_at')) {
                    $update['updated_at'] = now();
                }

                DB::table('workspace_invitations')
                    ->where('id', $invite->id)
                    ->update($update);

                $acceptedWorkspaceIds[] = $workspaceId;
            }
        });

        if ($acceptedWorkspaceIds !== []) {
            Cache::forget(sprintf('workspace-context:user-memberships:%s', $supabaseUserId));
            foreach (array_unique($acceptedWorkspaceIds) as $workspaceId) {
                Cache::forget(sprintf('workspace-context:membership:%s:%s', $workspaceId, $supabaseUserId));
            }
        }
    }

    private function canAddSeatToWorkspace(object $workspace, string $userId): bool
    {
        $billingAccountId = trim((string) ($workspace->billing_account_id ?? ''));
        $workspaceIds = $billingAccountId !== ''
            ? DB::table('workspaces')->where('billing_account_id', $billingAccountId)->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [(string) $workspace->workspace_id];

        if (empty($workspaceIds)) {
            return false;
        }

        $alreadyInAccount = WorkspaceMember::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('user_id', $userId)
            ->exists();

        if ($alreadyInAccount) {
            return true;
        }

        $planId = $billingAccountId !== ''
            ? (string) (DB::table('billing_accounts')->where('id', $billingAccountId)->value('plan_id') ?: ($workspace->plan_id ?? 'free'))
            : (string) ($workspace->plan_id ?? 'free');
        $maxMembers = (int) (DB::table('plans')->where('id', $planId ?: 'free')->value('max_members') ?: 1);
        $activeSeats = WorkspaceMember::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->distinct('user_id')
            ->count('user_id');

        return $activeSeats < max(1, $maxMembers);
    }
}
