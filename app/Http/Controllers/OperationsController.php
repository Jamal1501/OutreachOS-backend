<?php

namespace App\Http\Controllers;

use App\Models\CreatorSuppression;
use App\Models\SupportRequest;
use App\Services\CreatorSuppressionService;
use App\Services\ProviderSpendGuardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OperationsController extends Controller
{
    public function __construct(
        private ProviderSpendGuardService $providerSpend,
        private CreatorSuppressionService $creatorSuppressions,
    ) {}

    public function providerSpend(): mixed
    {
        return response()->json([
            'message' => 'Provider spend controls fetched',
            'data' => [
                'checkedAt' => now()->toIso8601String(),
                'providers' => [
                    $this->providerSpend->overview('apify'),
                    $this->providerSpend->overview('openai'),
                ],
            ],
        ]);
    }

    public function access(): mixed
    {
        return response()->json([
            'data' => ['allowed' => true],
        ]);
    }

    public function overview(): mixed
    {
        $heartbeats = Schema::hasTable('operational_heartbeats')
            ? DB::table('operational_heartbeats')
                ->whereIn('name', ['scheduler', 'queue-worker'])
                ->get(['name', 'last_seen_at', 'metadata'])
                ->keyBy('name')
            : collect();
        $processes = collect(['scheduler', 'queue-worker'])->mapWithKeys(function (string $name) use ($heartbeats) {
            $heartbeat = $heartbeats->get($name);
            $lastSeenAt = $heartbeat?->last_seen_at;
            $stale = ! $lastSeenAt || CarbonImmutable::parse($lastSeenAt)->lt(now()->subMinutes(3));
            $metadata = $heartbeat && is_string($heartbeat->metadata)
                ? (json_decode($heartbeat->metadata, true) ?: [])
                : [];

            return [$name => [
                'status' => $stale ? 'stale' : (($metadata['state'] ?? null) === 'busy' ? 'busy' : 'healthy'),
                'lastSeenAt' => $lastSeenAt,
                'stage' => $metadata['stage'] ?? null,
                'jobId' => $metadata['jobId'] ?? null,
            ]];
        })->all();

        return response()->json([
            'data' => [
                'checkedAt' => now()->toIso8601String(),
                'processes' => $processes,
                'queue' => [
                    'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
                    'failedLast24Hours' => Schema::hasTable('failed_jobs')
                        ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()
                        : null,
                ],
                'support' => [
                    'open' => Schema::hasTable('support_requests')
                        ? SupportRequest::query()->where('ticket_status', 'open')->count()
                        : 0,
                    'inProgress' => Schema::hasTable('support_requests')
                        ? SupportRequest::query()->where('ticket_status', 'in_progress')->count()
                        : 0,
                    'failedEmailDeliveries' => Schema::hasTable('support_requests')
                        ? SupportRequest::query()->where('status', 'failed')->count()
                        : 0,
                ],
                'customers' => [
                    'users' => Schema::hasTable('users') ? DB::table('users')->count() : null,
                    'workspaces' => Schema::hasTable('workspaces')
                        ? DB::table('workspaces')->count()
                        : null,
                ],
            ],
        ]);
    }

    public function updateProviderSpendControl(Request $request): mixed
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(['apify', 'openai'])],
            'scope' => ['required', 'string', Rule::in(['global', 'workspace'])],
            'workspaceId' => ['nullable', 'uuid', Rule::requiredIf(fn () => $request->input('scope') === 'workspace')],
            'dailyLimitUsd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'overrideLimitUsd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'overrideUntil' => ['nullable', 'date', 'after:now', Rule::requiredIf(fn () => (bool) $request->input('bypassTemporarily') || $request->filled('overrideLimitUsd'))],
            'overrideReason' => ['nullable', 'string', 'max:300', Rule::requiredIf(fn () => (bool) $request->input('bypassTemporarily') || $request->filled('overrideLimitUsd'))],
            'bypassTemporarily' => ['nullable', 'boolean'],
            'clearOverride' => ['nullable', 'boolean'],
        ]);

        $workspaceId = trim((string) ($validated['workspaceId'] ?? '')) ?: null;
        if ($workspaceId && ! DB::table('workspaces')->where('id', $workspaceId)->exists()) {
            return response()->json(['error' => 'Workspace not found.'], 404);
        }

        $clearOverride = (bool) ($validated['clearOverride'] ?? false);
        $updateDailyLimit = array_key_exists('dailyLimitUsd', $validated);
        $updateOverride = $clearOverride
            || (bool) ($validated['bypassTemporarily'] ?? false)
            || array_key_exists('overrideLimitUsd', $validated)
            || array_key_exists('overrideUntil', $validated);
        $overrideUntil = $clearOverride || empty($validated['overrideUntil'])
            ? null
            : CarbonImmutable::parse((string) $validated['overrideUntil']);
        $overrideLimit = $clearOverride || (bool) ($validated['bypassTemporarily'] ?? false)
            ? null
            : (isset($validated['overrideLimitUsd']) ? (float) $validated['overrideLimitUsd'] : null);

        $control = $this->providerSpend->updateControl(
            provider: (string) $validated['provider'],
            scope: (string) $validated['scope'],
            workspaceId: $workspaceId,
            dailyLimitUsd: isset($validated['dailyLimitUsd']) ? (float) $validated['dailyLimitUsd'] : null,
            overrideLimitUsd: $overrideLimit,
            overrideUntil: $overrideUntil,
            overrideReason: $clearOverride ? null : ($validated['overrideReason'] ?? null),
            updatedByUserId: (string) $request->attributes->get('supabase_user_id'),
            updateDailyLimit: $updateDailyLimit,
            updateOverride: $updateOverride,
        );

        return response()->json([
            'message' => 'Provider spend control updated',
            'data' => $control,
        ]);
    }

    public function incidentBanner(): mixed
    {
        $banner = Schema::hasTable('platform_incident_banners')
            ? DB::table('platform_incident_banners')->orderByDesc('updated_at')->first()
            : null;

        return response()->json([
            'data' => $banner ? [
                'id' => $banner->id,
                'enabled' => (bool) $banner->enabled,
                'severity' => $banner->severity,
                'message' => $banner->message,
                'startsAt' => $banner->starts_at,
                'expiresAt' => $banner->expires_at,
                'updatedAt' => $banner->updated_at,
                'updatedByUserId' => $banner->updated_by_user_id,
            ] : null,
        ]);
    }

    public function updateIncidentBanner(Request $request): mixed
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'severity' => ['required', 'string', Rule::in(['info', 'warning', 'critical'])],
            'message' => ['required', 'string', 'min:3', 'max:500'],
            'startsAt' => ['nullable', 'date'],
            'expiresAt' => [
                'nullable',
                'date',
                'after:now',
                Rule::when($request->filled('startsAt'), ['after:startsAt']),
            ],
        ]);
        $userId = (string) $request->attributes->get('supabase_user_id');
        DB::transaction(function () use ($validated, $userId) {
            DB::table('platform_incident_banners')->where('enabled', true)->update([
                'enabled' => false,
                'updated_at' => now(),
            ]);
            DB::table('platform_incident_banners')->insert([
                'id' => (string) Str::uuid(),
                'enabled' => (bool) $validated['enabled'],
                'severity' => $validated['severity'],
                'message' => trim((string) $validated['message']),
                'starts_at' => $validated['startsAt'] ?? null,
                'expires_at' => $validated['expiresAt'] ?? null,
                'updated_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->incidentBanner();
    }

    public function supportRequests(Request $request): mixed
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['open', 'in_progress', 'resolved'])],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['perPage'] ?? 20);
        $query = SupportRequest::query()
            ->with('workspace:id,name')
            ->when(isset($validated['status']), fn ($builder) => $builder->where('ticket_status', $validated['status']))
            ->orderByRaw("CASE ticket_status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at');
        $total = (clone $query)->count();

        return response()->json([
            'data' => [
                'items' => $query->forPage($page, $perPage)->get()->map(fn (SupportRequest $ticket) => [
                    'id' => (string) $ticket->id,
                    'reference' => $ticket->reference,
                    'workspaceId' => $ticket->workspace_id,
                    'workspaceName' => $ticket->workspace?->name,
                    'email' => $ticket->email,
                    'category' => $ticket->category,
                    'subject' => $ticket->subject,
                    'message' => $ticket->message,
                    'page' => $ticket->page,
                    'ticketStatus' => $ticket->ticket_status,
                    'deliveryStatus' => $ticket->status,
                    'createdAt' => $ticket->created_at?->toIso8601String(),
                    'resolvedAt' => $ticket->resolved_at?->toIso8601String(),
                ])->values(),
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => $total,
                    'lastPage' => max(1, (int) ceil($total / $perPage)),
                ],
            ],
        ]);
    }

    public function updateSupportRequest(Request $request, string $id): mixed
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['open', 'in_progress', 'resolved'])],
        ]);
        $ticket = SupportRequest::query()->findOrFail($id);
        $ticket->ticket_status = $validated['status'];
        $ticket->updated_by_operator_id = (string) $request->attributes->get('supabase_user_id');
        $ticket->resolved_at = $validated['status'] === 'resolved' ? now() : null;
        $ticket->save();

        return response()->json([
            'message' => 'Support request updated.',
            'data' => [
                'id' => (string) $ticket->id,
                'ticketStatus' => $ticket->ticket_status,
                'resolvedAt' => $ticket->resolved_at?->toIso8601String(),
            ],
        ]);
    }

    public function creatorSuppressions(): mixed
    {
        return response()->json([
            'data' => CreatorSuppression::query()
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (CreatorSuppression $suppression) => [
                    'id' => (string) $suppression->id,
                    'platform' => $suppression->platform,
                    'handle' => $suppression->normalized_handle,
                    'hasEmail' => filled($suppression->email_hash),
                    'reason' => $suppression->reason,
                    'source' => $suppression->source,
                    'createdAt' => $suppression->created_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    public function createCreatorSuppression(Request $request): mixed
    {
        $validated = $request->validate([
            'platform' => ['nullable', 'string', Rule::in(['instagram', 'tiktok'])],
            'handle' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => ! $request->filled('email'))],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::requiredIf(fn () => ! $request->filled('handle'))],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $suppression = $this->creatorSuppressions->suppress(
            $validated['platform'] ?? null,
            $validated['handle'] ?? null,
            $validated['email'] ?? null,
            $validated['reason'],
            (string) $request->attributes->get('supabase_user_id'),
        );

        return response()->json([
            'message' => 'Creator suppression added and matching CRM records removed.',
            'data' => [
                'id' => (string) $suppression->id,
                'platform' => $suppression->platform,
                'handle' => $suppression->normalized_handle,
                'hasEmail' => filled($suppression->email_hash),
                'reason' => $suppression->reason,
                'createdAt' => $suppression->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function deleteCreatorSuppression(string $id): mixed
    {
        CreatorSuppression::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Creator suppression removed.']);
    }
}
