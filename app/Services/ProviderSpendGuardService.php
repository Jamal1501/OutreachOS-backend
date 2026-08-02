<?php

namespace App\Services;

use App\Exceptions\ProviderSpendLimitException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProviderSpendGuardService
{
    public function assertCanReserve(string $workspaceId, string $provider, float $estimatedCostUsd): void
    {
        if (! config('outreach.provider_spend.enabled', true) || $estimatedCostUsd <= 0) {
            return;
        }

        if (! Schema::hasTable('provider_spend_controls')) {
            throw new ProviderSpendLimitException([
                'workspace_id' => $workspaceId,
                'provider' => Str::lower(trim($provider)),
                'blocked_scope' => 'global',
                'requested_usd' => round(max(0, $estimatedCostUsd), 4),
                'current_spend_usd' => 0,
                'projected_spend_usd' => round(max(0, $estimatedCostUsd), 4),
                'daily_limit_usd' => 0,
                'reason_code' => 'provider_spend_controls_unavailable',
            ]);
        }

        $provider = Str::lower(trim($provider));
        $estimatedCostUsd = round(max(0, $estimatedCostUsd), 4);
        $controls = [
            $this->lockedControl($provider, 'global', null),
            $this->lockedControl($provider, 'workspace:'.$workspaceId, $workspaceId),
        ];

        foreach ($controls as $control) {
            $scope = $control->workspace_id ? 'workspace' : 'global';
            $limit = $this->effectiveLimit($control, $scope);
            if ($limit === null) {
                continue;
            }

            $current = $this->dailySpend($provider, $control->workspace_id ? (string) $control->workspace_id : null);
            $projected = round($current + $estimatedCostUsd, 4);
            if ($projected <= $limit) {
                continue;
            }

            throw new ProviderSpendLimitException([
                'workspace_id' => $workspaceId,
                'provider' => $provider,
                'blocked_scope' => $scope,
                'requested_usd' => $estimatedCostUsd,
                'current_spend_usd' => $current,
                'projected_spend_usd' => $projected,
                'daily_limit_usd' => $limit,
                'reason_code' => 'provider_daily_spend_limit_reached',
            ]);
        }
    }

    public function recordBlock(ProviderSpendLimitException $exception, array $metadata = []): void
    {
        if (! Schema::hasTable('provider_spend_blocks')) {
            return;
        }

        $context = $exception->limitContext();
        DB::table('provider_spend_blocks')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $context['workspace_id'] ?? null,
            'provider' => $context['provider'] ?? 'unknown',
            'blocked_scope' => $context['blocked_scope'] ?? 'unknown',
            'requested_usd' => $context['requested_usd'] ?? 0,
            'current_spend_usd' => $context['current_spend_usd'] ?? 0,
            'projected_spend_usd' => $context['projected_spend_usd'] ?? 0,
            'daily_limit_usd' => $context['daily_limit_usd'] ?? 0,
            'reason_code' => $context['reason_code'] ?? 'provider_spend_blocked',
            'metadata' => json_encode($metadata),
            'created_at' => now(),
        ]);
    }

    public function updateControl(
        string $provider,
        string $scope,
        ?string $workspaceId,
        ?float $dailyLimitUsd,
        ?float $overrideLimitUsd,
        ?CarbonImmutable $overrideUntil,
        ?string $overrideReason,
        ?string $updatedByUserId,
        bool $updateDailyLimit = true,
        bool $updateOverride = true,
    ): array {
        $provider = Str::lower(trim($provider));
        $scopeKey = $scope === 'global' ? 'global' : 'workspace:'.$workspaceId;
        $this->ensureControl($provider, $scopeKey, $scope === 'global' ? null : $workspaceId);

        $updates = [
            'updated_by_user_id' => $updatedByUserId,
            'updated_at' => now(),
        ];
        if ($updateDailyLimit) {
            $updates['daily_limit_usd'] = $dailyLimitUsd;
        }
        if ($updateOverride) {
            $updates['override_limit_usd'] = $overrideLimitUsd;
            $updates['override_until'] = $overrideUntil;
            $updates['override_reason'] = $overrideReason;
        }

        DB::table('provider_spend_controls')
            ->where('provider', $provider)
            ->where('scope_key', $scopeKey)
            ->update($updates);

        return $this->controlSnapshot($provider, $scopeKey, $scope === 'global' ? null : $workspaceId);
    }

    public function overview(string $provider = 'apify'): array
    {
        $provider = Str::lower(trim($provider));
        $global = $this->controlSnapshot($provider, 'global', null);
        $workspaces = DB::table('workspaces')
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(fn ($workspace) => array_merge(
                ['workspaceName' => (string) $workspace->name],
                $this->controlSnapshot($provider, 'workspace:'.$workspace->id, (string) $workspace->id),
            ))
            ->values()
            ->all();

        $recentBlocks = Schema::hasTable('provider_spend_blocks')
            ? DB::table('provider_spend_blocks')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'provider_spend_blocks.workspace_id')
                ->where('provider_spend_blocks.provider', $provider)
                ->orderByDesc('provider_spend_blocks.created_at')
                ->limit(50)
                ->get([
                    'provider_spend_blocks.id',
                    'provider_spend_blocks.workspace_id',
                    'workspaces.name as workspace_name',
                    'provider_spend_blocks.blocked_scope',
                    'provider_spend_blocks.requested_usd',
                    'provider_spend_blocks.current_spend_usd',
                    'provider_spend_blocks.projected_spend_usd',
                    'provider_spend_blocks.daily_limit_usd',
                    'provider_spend_blocks.reason_code',
                    'provider_spend_blocks.created_at',
                ])
            : collect();

        return [
            'provider' => $provider,
            'date' => now()->toDateString(),
            'global' => $global,
            'workspaces' => $workspaces,
            'recentBlocks' => $recentBlocks,
        ];
    }

    private function lockedControl(string $provider, string $scopeKey, ?string $workspaceId): object
    {
        $this->ensureControl($provider, $scopeKey, $workspaceId);

        return DB::table('provider_spend_controls')
            ->where('provider', $provider)
            ->where('scope_key', $scopeKey)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureControl(string $provider, string $scopeKey, ?string $workspaceId): void
    {
        DB::table('provider_spend_controls')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'provider' => $provider,
            'scope_key' => $scopeKey,
            'workspace_id' => $workspaceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function controlSnapshot(string $provider, string $scopeKey, ?string $workspaceId): array
    {
        $this->ensureControl($provider, $scopeKey, $workspaceId);
        $control = DB::table('provider_spend_controls')
            ->where('provider', $provider)
            ->where('scope_key', $scopeKey)
            ->firstOrFail();
        $scope = $workspaceId ? 'workspace' : 'global';
        $limit = $this->effectiveLimit($control, $scope);
        $spent = $this->dailySpend($provider, $workspaceId);

        return [
            'scope' => $scope,
            'workspaceId' => $workspaceId,
            'configuredDailyLimitUsd' => $control->daily_limit_usd !== null ? (float) $control->daily_limit_usd : null,
            'effectiveDailyLimitUsd' => $limit,
            'spentAndReservedTodayUsd' => $spent,
            'remainingTodayUsd' => $limit === null ? null : round(max(0, $limit - $spent), 4),
            'overrideLimitUsd' => $control->override_limit_usd !== null ? (float) $control->override_limit_usd : null,
            'overrideUntil' => $control->override_until,
            'overrideReason' => $control->override_reason,
            'isTemporarilyBypassed' => $this->activeOverride($control) && $control->override_limit_usd === null,
            'isBlocked' => $limit !== null && $spent >= $limit,
        ];
    }

    private function effectiveLimit(object $control, string $scope): ?float
    {
        if ($this->activeOverride($control)) {
            return $control->override_limit_usd !== null ? max(0, (float) $control->override_limit_usd) : null;
        }

        if ($control->daily_limit_usd !== null) {
            return max(0, (float) $control->daily_limit_usd);
        }

        $configured = $scope === 'global'
            ? config('outreach.provider_spend.global_daily_limit_usd', 50)
            : config('outreach.provider_spend.workspace_daily_limit_usd', 20);

        return is_numeric($configured) ? max(0, (float) $configured) : null;
    }

    private function activeOverride(object $control): bool
    {
        return $control->override_until !== null && CarbonImmutable::parse($control->override_until)->isFuture();
    }

    private function dailySpend(string $provider, ?string $workspaceId): float
    {
        if (! Schema::hasTable('workspace_usage_events')) {
            return 0;
        }

        return round((float) DB::table('workspace_usage_events')
            ->where('provider', $provider)
            ->whereIn('status', ['reserved', 'consumed', 'refunded'])
            ->where('created_at', '>=', now()->startOfDay())
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->sum('provider_cost_usd'), 4);
    }
}
