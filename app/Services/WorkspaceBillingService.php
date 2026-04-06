<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\WorkspaceCreditWallet;
use App\Models\WorkspaceSubscription;
use App\Models\WorkspaceUsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WorkspaceBillingService
{
    private const PLAN_ALIASES = [
        'trial' => 'free',
    ];

    public function summary(string $workspaceId): array
    {
        [$subscription, $wallet, $plan] = $this->ensureWorkspaceBilling($workspaceId);

        return [
            'workspaceId' => $workspaceId,
            'subscription' => [
                'planId' => $subscription->plan_id,
                'status' => $subscription->status,
                'currentPeriodStart' => optional($subscription->current_period_start)?->toIso8601String(),
                'currentPeriodEnd' => optional($subscription->current_period_end)?->toIso8601String(),
                'trialEndsAt' => optional($subscription->trial_ends_at)?->toIso8601String(),
            ],
            'wallet' => [
                'scrapeCreditsBalance' => (int) $wallet->scrape_credits_balance,
                'aiCreditsBalance' => (int) $wallet->ai_credits_balance,
                'bonusScrapeCredits' => (int) $wallet->bonus_scrape_credits,
                'bonusAiCredits' => (int) $wallet->bonus_ai_credits,
                'lifetimeScrapeUsed' => (int) $wallet->lifetime_scrape_used,
                'lifetimeAiUsed' => (int) $wallet->lifetime_ai_used,
            ],
            'entitlements' => [
                'monthlyScrapeCredits' => (int) Arr::get($plan, 'monthly_scrape_credits', 0),
                'monthlyAiCredits' => (int) Arr::get($plan, 'monthly_ai_credits', 0),
                'trialScrapeCredits' => (int) Arr::get($plan, 'trial_scrape_credits', 0),
                'trialAiCredits' => (int) Arr::get($plan, 'trial_ai_credits', 0),
                'topupPriceMultiplier' => (float) Arr::get($plan, 'topup_price_multiplier', 1),
            ],
        ];
    }

    public function reserveApify(?string $workspaceId, ?string $actorKey, ?string $actorId, array $input, ?float $maxChargeUsd = null): array
    {
        if (!$workspaceId) {
            throw new RuntimeException('Workspace billing requires a valid workspace context.');
        }

        $estimate = $this->estimateApifyCredits($actorKey, $actorId, $input);

        return $this->reserve(
            workspaceId: $workspaceId,
            type: $estimate['type'],
            bucket: 'scrape',
            units: $estimate['units'],
            creditCost: $estimate['credit_cost'],
            provider: 'apify',
            source: (string) ($actorKey ?: $actorId ?: 'apify_run'),
            metadata: [
                'actor_key' => $actorKey,
                'actor_id' => $actorId,
                'max_total_charge_usd' => $maxChargeUsd,
                'input' => $input,
            ],
        );
    }

    public function reserveAi(?string $workspaceId, string $operation, array $context = []): array
    {
        if (!$workspaceId) {
            throw new RuntimeException('Workspace billing requires a valid workspace context.');
        }

        $units = max(1, (int) ($context['units'] ?? 1));
        $creditCost = max(1, (int) ($context['credit_cost'] ?? (int) config('outreach.billing.ai_request_credit_cost', 1)));

        return $this->reserve(
            workspaceId: $workspaceId,
            type: 'ai_generation',
            bucket: 'ai',
            units: $units,
            creditCost: $creditCost,
            provider: 'openai',
            source: $operation,
            metadata: $context,
        );
    }

    public function consumeReservation(string $usageEventId, ?float $providerCostUsd = null, array $metadata = [], ?string $referenceId = null): void
    {
        DB::transaction(function () use ($usageEventId, $providerCostUsd, $metadata, $referenceId) {
            $event = WorkspaceUsageEvent::query()->lockForUpdate()->find($usageEventId);
            if (!$event || $event->status !== 'reserved') {
                return;
            }

            $event->status = 'consumed';
            $event->provider_cost_usd = $providerCostUsd;
            $event->reference_id = $referenceId ?: $event->reference_id;
            $event->metadata = array_merge((array) ($event->metadata ?? []), $metadata);
            $event->consumed_at = now();
            $event->save();

            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $event->workspace_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                return;
            }

            if ($event->credit_bucket === 'scrape') {
                $wallet->lifetime_scrape_used = (int) $wallet->lifetime_scrape_used + (int) $event->credit_cost;
            } else {
                $wallet->lifetime_ai_used = (int) $wallet->lifetime_ai_used + (int) $event->credit_cost;
            }

            $wallet->save();
        });
    }

    public function refundReservation(string $usageEventId, string $reason, array $metadata = []): void
    {
        DB::transaction(function () use ($usageEventId, $reason, $metadata) {
            $event = WorkspaceUsageEvent::query()->lockForUpdate()->find($usageEventId);
            if (!$event || $event->status !== 'reserved') {
                return;
            }

            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $event->workspace_id)
                ->lockForUpdate()
                ->first();

            if ($wallet) {
                if ($event->credit_bucket === 'scrape') {
                    $wallet->scrape_credits_balance = (int) $wallet->scrape_credits_balance + (int) $event->credit_cost;
                } else {
                    $wallet->ai_credits_balance = (int) $wallet->ai_credits_balance + (int) $event->credit_cost;
                }
                $wallet->save();
            }

            $event->status = 'refunded';
            $event->error_message = $reason;
            $event->metadata = array_merge((array) ($event->metadata ?? []), $metadata);
            $event->refunded_at = now();
            $event->save();
        });
    }

    public function ensureWorkspaceBilling(string $workspaceId): array
    {
        return DB::transaction(function () use ($workspaceId) {
            $workspace = DB::table('workspaces')->where('id', $workspaceId)->first();
            if (!$workspace) {
                throw new RuntimeException('Workspace not found for billing.');
            }

            $planId = $this->normalizePlanId((string) ($workspace->plan_id ?? 'free'));
            $plan = $this->resolvePlan($planId);
            $now = CarbonImmutable::now();

            $subscription = WorkspaceSubscription::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                $subscription = WorkspaceSubscription::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'plan_id' => $planId,
                    'status' => $planId === 'free' ? 'trialing' : 'active',
                    'current_period_start' => $now,
                    'current_period_end' => $now->addMonth(),
                    'trial_ends_at' => $planId === 'free' ? $now->addDays((int) config('outreach.billing.trial_days', 14)) : null,
                    'metadata' => ['bootstrap' => true],
                ]);
            } elseif (!$subscription->plan_id) {
                $subscription->plan_id = $planId;
                $subscription->save();
            }

            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $wallet = WorkspaceCreditWallet::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'scrape_credits_balance' => $planId === 'free'
                        ? (int) Arr::get($plan, 'trial_scrape_credits', 0)
                        : (int) Arr::get($plan, 'monthly_scrape_credits', 0),
                    'ai_credits_balance' => $planId === 'free'
                        ? (int) Arr::get($plan, 'trial_ai_credits', 0)
                        : (int) Arr::get($plan, 'monthly_ai_credits', 0),
                    'bonus_scrape_credits' => 0,
                    'bonus_ai_credits' => 0,
                    'lifetime_scrape_used' => 0,
                    'lifetime_ai_used' => 0,
                    'metadata' => ['bootstrap' => true],
                ]);
            }

            return [$subscription, $wallet, $plan];
        });
    }

    private function reserve(
        string $workspaceId,
        string $type,
        string $bucket,
        int $units,
        int $creditCost,
        string $provider,
        string $source,
        array $metadata = [],
    ): array {
        [$subscription] = $this->ensureWorkspaceBilling($workspaceId);

        if ($subscription->status === 'canceled' || $subscription->status === 'past_due') {
            throw new InsufficientCreditsException('Workspace subscription is not active.', [
                'subscriptionStatus' => $subscription->status,
            ]);
        }

        return DB::transaction(function () use ($workspaceId, $type, $bucket, $units, $creditCost, $provider, $source, $metadata) {
            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->firstOrFail();

            $balanceField = $bucket === 'scrape' ? 'scrape_credits_balance' : 'ai_credits_balance';
            $available = (int) $wallet->{$balanceField};

            if ($available < $creditCost) {
                throw new InsufficientCreditsException('Not enough credits available for this action.', [
                    'bucket' => $bucket,
                    'required' => $creditCost,
                    'available' => $available,
                ]);
            }

            $wallet->{$balanceField} = $available - $creditCost;
            $wallet->save();

            $event = WorkspaceUsageEvent::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'type' => $type,
                'credit_bucket' => $bucket,
                'units' => $units,
                'credit_cost' => $creditCost,
                'provider' => $provider,
                'source' => $source,
                'status' => 'reserved',
                'metadata' => $metadata,
            ]);

            return [
                'usage_event_id' => $event->id,
                'credit_bucket' => $bucket,
                'credit_cost' => $creditCost,
                'units' => $units,
                'remaining_balance' => (int) $wallet->{$balanceField},
            ];
        });
    }

    private function estimateApifyCredits(?string $actorKey, ?string $actorId, array $input): array
    {
        $normalizedActor = strtolower((string) ($actorKey ?: $actorId ?: ''));
        $isProfile = str_contains($normalizedActor, 'profile');
        $targetCount = $this->estimateTargetCount($input);

        if ($isProfile) {
            $units = max(1, $targetCount);
            $perProfileCost = max(1, (int) config('outreach.billing.enrichment_credit_cost', 5));

            return [
                'type' => 'enrichment',
                'units' => $units,
                'credit_cost' => $units * $perProfileCost,
            ];
        }

        $units = max(1, $this->estimateDiscoveryCount($input));

        return [
            'type' => 'scrape',
            'units' => $units,
            'credit_cost' => $units,
        ];
    }

    private function estimateTargetCount(array $input): int
    {
        foreach (['profileUrls', 'urls', 'usernames', 'handles', 'profiles'] as $key) {
            $value = $input[$key] ?? null;
            if (is_array($value) && count($value) > 0) {
                return count(array_filter($value, fn ($v) => trim((string) $v) !== ''));
            }
        }

        foreach (['profileUrl', 'url', 'username', 'handle'] as $key) {
            if (trim((string) ($input[$key] ?? '')) !== '') {
                return 1;
            }
        }

        return 1;
    }

    private function estimateDiscoveryCount(array $input): int
    {
        foreach (['resultsLimit', 'results_limit', 'maxItems', 'max_items', 'limit'] as $key) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                return max(1, min(1000, (int) $input[$key]));
            }
        }

        foreach (['hashtags', 'searchTerms', 'queries', 'keywords'] as $key) {
            $value = $input[$key] ?? null;
            if (is_array($value) && count($value) > 0) {
                return max(1, min(500, count($value) * 25));
            }
        }

        return max(1, (int) config('outreach.billing.default_discovery_credit_cost', 25));
    }

    private function resolvePlan(string $planId): array
    {
        $row = DB::table('plans')->where('id', $planId)->first();
        if ($row) {
            return (array) $row;
        }

        return [
            'id' => $planId,
            'monthly_scrape_credits' => 0,
            'monthly_ai_credits' => 0,
            'trial_scrape_credits' => 200,
            'trial_ai_credits' => 20,
            'topup_price_multiplier' => 1.0,
        ];
    }

    private function normalizePlanId(string $planId): string
    {
        $planId = strtolower(trim($planId));
        return self::PLAN_ALIASES[$planId] ?? ($planId !== '' ? $planId : 'free');
    }
}
