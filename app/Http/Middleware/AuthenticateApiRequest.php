<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = trim((string) $request->bearerToken());

        if ($bearerToken !== '') {
            $supabaseUser = $this->resolveSupabaseUser($bearerToken);
            if (!$supabaseUser) {
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

            return $next($request);
        }

        $legacyKey = trim((string) $request->header('X-APP-KEY'));
        $configuredKey = trim((string) config('services.app_security.key'));
        $allowLegacy = (bool) config('services.app_security.allow_legacy_key', true);

        if ($allowLegacy && $configuredKey !== '' && hash_equals($configuredKey, $legacyKey)) {
            $request->attributes->set('legacy_api_access', true);
            return $next($request);
        }

        return response()->json([
            'error' => 'Authentication required.',
        ], 401);
    }

    private function resolveSupabaseUser(string $bearerToken): ?array
    {
        $supabaseUrl = rtrim((string) config('services.supabase.url'), '/');
        $supabaseApiKey = trim((string) (config('services.supabase.service_role_key') ?: config('services.supabase.anon_key')));

        if ($supabaseUrl === '' || $supabaseApiKey === '') {
            return null;
        }

        $cacheKey = 'supabase:user:' . sha1($bearerToken);

        return Cache::remember($cacheKey, now()->addSeconds(90), function () use ($supabaseUrl, $supabaseApiKey, $bearerToken) {
            $response = Http::timeout((int) config('services.supabase.auth_timeout', 15))
                ->acceptJson()
                ->withHeaders([
                    'apikey' => $supabaseApiKey,
                    'Authorization' => 'Bearer ' . $bearerToken,
                ])
                ->get($supabaseUrl . '/auth/v1/user');

            if (!$response->successful()) {
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

        if (!$user) {
            $user = new User();
            $user->password = Str::random(32);
        }

        $user->supabase_user_id = $supabaseUserId !== '' ? $supabaseUserId : $user->supabase_user_id;
        $user->email = $email !== '' ? $email : ($user->email ?: 'missing-email@example.invalid');
        $user->name = $displayName;
        $user->email_verified_at = !empty($supabaseUser['email_confirmed_at']) ? now() : $user->email_verified_at;
        $user->save();

        return $user;
    }
}
