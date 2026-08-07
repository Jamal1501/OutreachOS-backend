<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\ProviderSpendLimitException;
use App\Models\CreditPackage;
use App\Models\CreditPurchase;
use App\Models\WorkspaceCreditWallet;
use App\Models\WorkspaceSubscription;
use App\Models\WorkspaceUsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class WorkspaceBillingService
{
    private const CREDIT_ELIGIBLE_SUBSCRIPTION_STATUSES = ['active', 'trialing'];

    private const CREDIT_BLOCKED_SUBSCRIPTION_STATUSES = ['past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired'];

    private const PLAN_ALIASES = [
        'trial' => 'free',
        'starter' => 'free',
        'free_trial' => 'free',
        'pro_trial' => 'pro',
        'enterprise_trial' => 'enterprise',
    ];

    private const DEFAULT_PLAN_PRICES_CENTS = [
        'free' => 0,
        'pro' => 14900,
        'enterprise' => 39900,
    ];

    private const DEFAULT_CREDIT_PACKAGES = [
        [
            'id' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Extra Workflow Pack',
            'scrape_credits' => 500,
            'ai_credits' => 50,
            'price_usd' => 15.00,
            'allowed_plan_ids' => ['pro', 'enterprise'],
        ],
        [
            'id' => '22222222-2222-4222-8222-222222222222',
            'name' => 'Growth Workflow Pack',
            'scrape_credits' => 2000,
            'ai_credits' => 250,
            'price_usd' => 49.00,
            'allowed_plan_ids' => ['pro', 'enterprise'],
        ],
        [
            'id' => '33333333-3333-4333-8333-333333333333',
            'name' => 'Scale Workflow Pack',
            'scrape_credits' => 6000,
            'ai_credits' => 800,
            'price_usd' => 119.00,
            'allowed_plan_ids' => ['pro', 'enterprise'],
        ],
    ];

    public function __construct(
        private ScraperRegistryService $scrapers,
        private ObservabilityService $observability,
        private ProviderSpendGuardService $providerSpend,
    ) {}

    public function summary(string $workspaceId): array
    {
        [$subscription, $wallet, $plan, $billingAccount] = $this->readWorkspaceBillingSnapshot($workspaceId);

        $currentPlanId = $this->normalizePlanId((string) ($subscription->plan_id ?: Arr::get($plan, 'id', 'free')));
        $periodStart = $subscription->current_period_start ? CarbonImmutable::instance($subscription->current_period_start) : null;
        $periodEnd = $subscription->current_period_end ? CarbonImmutable::instance($subscription->current_period_end) : null;
        $usage = $this->billingAccountUsageEstimate((string) $billingAccount->id, $periodStart, $periodEnd);
        $activeWorkspaceUsage = $this->customerUsageEstimate($workspaceId, $periodStart, $periodEnd);

        return [
            'workspaceId' => $workspaceId,
            'billingAccount' => [
                'id' => (string) $billingAccount->id,
                'name' => (string) $billingAccount->name,
                'ownerUserId' => (string) $billingAccount->owner_user_id,
                'primaryWorkspaceId' => (string) ($billingAccount->primary_workspace_id ?: ''),
                'planId' => $currentPlanId,
                'billingScope' => 'shared_account',
            ],
            'currentPlanId' => $currentPlanId,
            'planState' => $this->planState($workspaceId, $subscription, $currentPlanId),
            'subscription' => [
                'planId' => $currentPlanId,
                'status' => $subscription->status,
                'managedByStripe' => trim((string) ($subscription->stripe_customer_id ?? '')) !== '',
                'hasActiveStripeSubscription' => trim((string) ($subscription->stripe_subscription_id ?? '')) !== ''
                    && in_array(strtolower((string) $subscription->status), ['active', 'trialing', 'past_due', 'unpaid', 'incomplete'], true),
                'cancelAtPeriodEnd' => (bool) data_get($subscription->metadata, 'cancel_at_period_end', false),
                'currentPeriodStart' => optional($subscription->current_period_start)?->toIso8601String(),
                'currentPeriodEnd' => optional($subscription->current_period_end)?->toIso8601String(),
                'trialEndsAt' => optional($subscription->trial_ends_at)?->toIso8601String(),
            ],
            'wallet' => [
                'scrapeCreditsBalance' => (int) $wallet->scrape_credits_balance,
                'aiCreditsBalance' => (int) $wallet->ai_credits_balance,
                'bonusScrapeCredits' => (int) $wallet->bonus_scrape_credits,
                'bonusAiCredits' => (int) $wallet->bonus_ai_credits,
                'totalScrapeCreditsAvailable' => (int) $wallet->scrape_credits_balance + (int) $wallet->bonus_scrape_credits,
                'totalAiCreditsAvailable' => (int) $wallet->ai_credits_balance + (int) $wallet->bonus_ai_credits,
                'lifetimeScrapeUsed' => (int) $wallet->lifetime_scrape_used,
                'lifetimeAiUsed' => (int) $wallet->lifetime_ai_used,
            ],
            'usage' => [
                'consumedScrapeCredits' => (int) ($usage['consumedScrapeCredits'] ?? 0),
                'consumedAiCredits' => (int) ($usage['consumedAiCredits'] ?? 0),
                'estimatedCreditSpendUsd' => (float) ($usage['estimatedCreditSpendUsd'] ?? 0),
                'estimatedOutreachInvestmentUsd' => (float) ($usage['estimatedOutreachInvestmentUsd'] ?? 0),
                'customerCreditValue' => $usage['customerCreditValue'] ?? $this->customerCreditValueForPlan($currentPlanId),
                'scope' => 'billing_account',
            ],
            'activeWorkspaceUsage' => array_merge($activeWorkspaceUsage, ['scope' => 'active_workspace']),
            'workspaceBreakdown' => $this->workspaceUsageBreakdown((string) $billingAccount->id, $periodStart, $periodEnd),
            'entitlements' => [
                'monthlyScrapeCredits' => (int) Arr::get($plan, 'monthly_scrape_credits', 0),
                'monthlyAiCredits' => (int) Arr::get($plan, 'monthly_ai_credits', 0),
                'trialScrapeCredits' => (int) Arr::get($plan, 'trial_scrape_credits', 0),
                'trialAiCredits' => (int) Arr::get($plan, 'trial_ai_credits', 0),
                'topupPriceMultiplier' => (float) Arr::get($plan, 'topup_price_multiplier', 1),
                'scraperModuleKeys' => array_values(array_map(fn (array $module) => $module['key'], $this->scrapers->availableForPlan((string) Arr::get($plan, 'id', 'free')))),
            ],
        ];
    }

    public function catalog(string $workspaceId): array
    {
        $this->ensureCatalogSeeded();
        [$subscription, $wallet, $currentPlan, $billingAccount] = $this->readWorkspaceBillingSnapshot($workspaceId);

        $currentPlanId = $this->normalizePlanId((string) ($subscription->plan_id ?: Arr::get($currentPlan, 'id', 'free')));

        $allowedCatalogPlanIds = ['free', 'pro', 'enterprise'];
        $planDisplayNames = [
            'free' => 'Evaluation',
            'pro' => 'Pro',
            'enterprise' => 'Agency',
        ];

        $plans = DB::table('plans')
            ->where('is_active', true)
            ->whereIn('id', $allowedCatalogPlanIds)
            ->orderByRaw("CASE id WHEN 'free' THEN 1 WHEN 'pro' THEN 2 WHEN 'enterprise' THEN 3 ELSE 4 END")
            ->get()
            ->map(function ($row) use ($currentPlanId, $planDisplayNames) {
                $data = (array) $row;
                $planId = $this->normalizePlanId((string) ($data['id'] ?? 'free'));
                $features = $this->publicPlanFeatures($planId, $this->normalizeJsonArray($data['features'] ?? []));
                $priceCents = $this->planPriceCents($planId);
                $scraperModules = $this->scrapers->availableForPlan($planId);

                return [
                    'id' => $planId,
                    'name' => $planDisplayNames[$planId] ?? (string) ($data['name'] ?? Str::headline($planId)),
                    'monthlyScrapeCredits' => (int) ($data['monthly_scrape_credits'] ?? 0),
                    'monthlyAiCredits' => (int) ($data['monthly_ai_credits'] ?? 0),
                    'trialScrapeCredits' => (int) ($data['trial_scrape_credits'] ?? 0),
                    'trialAiCredits' => (int) ($data['trial_ai_credits'] ?? 0),
                    'maxMembers' => (int) ($data['max_members'] ?? 0),
                    'maxCreators' => (int) ($data['max_creators'] ?? 0),
                    'features' => $features,
                    'scraperModuleKeys' => array_values(array_map(fn (array $module) => $module['key'], $scraperModules)),
                    'maxScraperDepth' => $this->maxScraperDepth($scraperModules),
                    'priceCents' => $priceCents,
                    'priceUsd' => round($priceCents / 100, 2),
                    'isCurrent' => $planId === $currentPlanId,
                ];
            })
            ->values()
            ->all();

        $multiplier = max(0.1, (float) Arr::get($currentPlan, 'topup_price_multiplier', 1));

        $packages = CreditPackage::query()
            ->where('active', true)
            ->orderBy('price_usd')
            ->get()
            ->map(function (CreditPackage $package) use ($multiplier, $currentPlanId) {
                $allowed = $this->normalizeJsonArray($package->allowed_plan_ids ?? []);
                if ($allowed !== [] && ! in_array($currentPlanId, $allowed, true)) {
                    return null;
                }

                $effectivePriceCents = (int) round(((float) $package->price_usd * 100) * $multiplier);

                return [
                    'id' => $package->id,
                    'name' => $package->name,
                    'scrapeCredits' => (int) $package->scrape_credits,
                    'aiCredits' => (int) $package->ai_credits,
                    'basePriceUsd' => round((float) $package->price_usd, 2),
                    'effectivePriceUsd' => round($effectivePriceCents / 100, 2),
                    'effectivePriceCents' => $effectivePriceCents,
                    'allowedPlanIds' => $allowed,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'currency' => (string) config('outreach.billing.currency', 'usd'),
            'checkoutEnabled' => trim((string) config('services.stripe.secret_key')) !== '',
            'currentPlanId' => $currentPlanId,
            'planState' => $this->planState($workspaceId, $subscription, $currentPlanId),
            'plans' => $plans,
            'packages' => $packages,
            'pricingModel' => [
                'customerUnit' => 'credits',
                'providerSpendVisibleToCustomer' => false,
                'topupPricing' => [
                    'currentPlanMultiplier' => $multiplier,
                    'freeMultiplier' => 1.25,
                    'proMultiplier' => 1.0,
                    'enterpriseMultiplier' => 1.0,
                ],
            ],
        ];
    }

    public function reserveApify(?string $workspaceId, ?string $moduleKey, ?string $actorKey, ?string $actorId, array $input, ?float $maxChargeUsd = null): array
    {
        if (! $workspaceId) {
            throw new RuntimeException('Workspace billing requires a valid workspace context.');
        }

        $estimate = $this->estimateApifyCredits($moduleKey, $actorKey, $actorId, $input);

        return $this->reserve(
            workspaceId: $workspaceId,
            type: $estimate['type'],
            bucket: (string) ($estimate['bucket'] ?? 'scrape'),
            units: $estimate['units'],
            creditCost: $estimate['credit_cost'],
            provider: 'apify',
            source: (string) ($moduleKey ?: $actorKey ?: $actorId ?: 'apify_run'),
            providerCostReservationUsd: $maxChargeUsd,
            metadata: [
                'module_key' => $moduleKey,
                'cost_class' => $estimate['cost_class'] ?? null,
                'actor_key' => $actorKey,
                'actor_id' => $actorId,
                'max_total_charge_usd' => $maxChargeUsd,
                'input' => $input,
            ],
        );
    }

    public function reserveAi(?string $workspaceId, string $operation, array $context = []): array
    {
        if (! $workspaceId) {
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
            providerCostReservationUsd: (float) config('outreach.provider_spend.openai_reservation_usd', 0.10),
            metadata: $context,
        );
    }

    public function consumeReservation(string $usageEventId, ?float $providerCostUsd = null, array $metadata = [], ?string $referenceId = null): void
    {
        DB::transaction(function () use ($usageEventId, $providerCostUsd, $metadata, $referenceId) {
            $event = WorkspaceUsageEvent::query()->lockForUpdate()->find($usageEventId);
            if (! $event || $event->status !== 'reserved') {
                return;
            }

            $event->status = 'consumed';
            $actualProviderCostUsd = $providerCostUsd !== null
                ? max(0, $providerCostUsd)
                : max(0, (float) ($event->provider_cost_reserved_usd ?? $event->provider_cost_usd ?? 0));
            $event->provider_cost_actual_usd = $actualProviderCostUsd;
            $event->provider_cost_usd = $actualProviderCostUsd;
            $event->reference_id = $referenceId ?: $event->reference_id;
            $event->metadata = array_merge((array) ($event->metadata ?? []), $metadata);
            $event->consumed_at = now();
            $event->save();

            $wallet = WorkspaceCreditWallet::query()
                ->when($event->billing_account_id, fn ($query) => $query->where('billing_account_id', $event->billing_account_id))
                ->when(! $event->billing_account_id, fn ($query) => $query->where('workspace_id', $event->workspace_id))
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
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

    public function refundReservation(string $usageEventId, string $reason, array $metadata = [], ?float $providerCostActualUsd = null): void
    {
        DB::transaction(function () use ($usageEventId, $reason, $metadata, $providerCostActualUsd) {
            $event = WorkspaceUsageEvent::query()->lockForUpdate()->find($usageEventId);
            if (! $event || $event->status !== 'reserved') {
                return;
            }

            $wallet = WorkspaceCreditWallet::query()
                ->when($event->billing_account_id, fn ($query) => $query->where('billing_account_id', $event->billing_account_id))
                ->when(! $event->billing_account_id, fn ($query) => $query->where('workspace_id', $event->workspace_id))
                ->lockForUpdate()
                ->first();

            if ($wallet) {
                $deductions = Arr::get((array) ($event->metadata ?? []), 'deductions', []);
                $base = max(0, (int) ($deductions['base'] ?? 0));
                $bonus = max(0, (int) ($deductions['bonus'] ?? 0));

                if ($event->credit_bucket === 'scrape') {
                    $wallet->scrape_credits_balance = (int) $wallet->scrape_credits_balance + $base;
                    $wallet->bonus_scrape_credits = (int) $wallet->bonus_scrape_credits + $bonus;
                } else {
                    $wallet->ai_credits_balance = (int) $wallet->ai_credits_balance + $base;
                    $wallet->bonus_ai_credits = (int) $wallet->bonus_ai_credits + $bonus;
                }
                $wallet->save();
            }

            $event->status = 'refunded';
            $providerStarted = trim((string) ($event->reference_id ?? '')) !== ''
                || trim((string) ($metadata['run_id'] ?? '')) !== '';
            $actualProviderCostUsd = $providerCostActualUsd !== null
                ? max(0, $providerCostActualUsd)
                : ($providerStarted
                    ? max(0, (float) ($event->provider_cost_reserved_usd ?? $event->provider_cost_usd ?? 0))
                    : 0.0);
            $event->provider_cost_actual_usd = $actualProviderCostUsd;
            $event->provider_cost_usd = $actualProviderCostUsd;
            $event->error_message = $reason;
            $event->metadata = array_merge((array) ($event->metadata ?? []), $metadata);
            $event->refunded_at = now();
            $event->save();
        });
    }

    public function settleReservationUnits(
        string $usageEventId,
        int $billableUnits,
        ?float $providerCostUsd = null,
        array $metadata = [],
        ?string $referenceId = null,
    ): void {
        DB::transaction(function () use ($usageEventId, $billableUnits, $providerCostUsd, $metadata, $referenceId) {
            $event = WorkspaceUsageEvent::query()->lockForUpdate()->find($usageEventId);
            if (! $event || $event->status !== 'reserved') {
                return;
            }

            $originalUnits = max(1, (int) $event->units);
            $settledUnits = min($originalUnits, max(0, $billableUnits));
            if ($settledUnits === 0) {
                $this->refundReservation($usageEventId, 'No billable units completed', $metadata, $providerCostUsd);

                return;
            }

            $originalCredits = max(0, (int) $event->credit_cost);
            $settledCredits = min($originalCredits, (int) ceil($originalCredits * ($settledUnits / $originalUnits)));
            $creditsToReturn = $originalCredits - $settledCredits;
            $eventMetadata = (array) ($event->metadata ?? []);
            $deductions = (array) Arr::get($eventMetadata, 'deductions', []);
            $originalBase = max(0, (int) ($deductions['base'] ?? 0));
            $originalBonus = max(0, (int) ($deductions['bonus'] ?? 0));
            $refundBonus = min($originalBonus, $creditsToReturn);
            $refundBase = min($originalBase, $creditsToReturn - $refundBonus);

            $wallet = WorkspaceCreditWallet::query()
                ->when($event->billing_account_id, fn ($query) => $query->where('billing_account_id', $event->billing_account_id))
                ->when(! $event->billing_account_id, fn ($query) => $query->where('workspace_id', $event->workspace_id))
                ->lockForUpdate()
                ->first();

            if ($wallet) {
                if ($event->credit_bucket === 'scrape') {
                    $wallet->scrape_credits_balance += $refundBase;
                    $wallet->bonus_scrape_credits += $refundBonus;
                    $wallet->lifetime_scrape_used += $settledCredits;
                } else {
                    $wallet->ai_credits_balance += $refundBase;
                    $wallet->bonus_ai_credits += $refundBonus;
                    $wallet->lifetime_ai_used += $settledCredits;
                }
                $wallet->save();
            }

            $eventMetadata['deductions'] = [
                'base' => $originalBase - $refundBase,
                'bonus' => $originalBonus - $refundBonus,
            ];
            $event->units = $settledUnits;
            $event->credit_cost = $settledCredits;
            $event->status = 'consumed';
            $reservedProviderCostUsd = max(0, (float) ($event->provider_cost_reserved_usd ?? $event->provider_cost_usd ?? 0));
            $actualProviderCostUsd = $providerCostUsd !== null
                ? max(0, $providerCostUsd)
                : round($reservedProviderCostUsd * ($settledUnits / $originalUnits), 4);
            $event->provider_cost_actual_usd = $actualProviderCostUsd;
            $event->provider_cost_usd = $actualProviderCostUsd;
            $event->reference_id = $referenceId ?: $event->reference_id;
            $event->metadata = array_merge($eventMetadata, $metadata, [
                'original_units' => $originalUnits,
                'original_credit_cost' => $originalCredits,
                'refunded_credit_cost' => $creditsToReturn,
                'partial_settlement' => $settledUnits < $originalUnits,
            ]);
            $event->consumed_at = now();
            $event->save();
        });
    }

    private function readWorkspaceBillingSnapshot(string $workspaceId): array
    {
        // Billing reads must not return stale subscription/wallet rows. Existing
        // workspaces can have a workspace.plan_id that was changed directly in
        // Supabase while workspace_subscriptions stayed on the old plan. Passing
        // every read through ensureWorkspaceBilling keeps summary and catalog on
        // the same effective plan and refills the wallet when the plan changes.
        return $this->ensureWorkspaceBilling($workspaceId);
    }

    public function ensureWorkspaceBilling(string $workspaceId): array
    {
        $this->ensureCatalogSeeded();

        return DB::transaction(function () use ($workspaceId) {
            [$workspace, $billingAccount] = $this->billingAccountForWorkspaceLocked($workspaceId);

            $accountPlanId = $this->normalizePlanId((string) ($billingAccount->plan_id ?: $workspace->plan_id ?: 'free'));
            $plan = $this->resolvePlan($accountPlanId);
            $now = CarbonImmutable::now();
            $canonicalWorkspaceId = (string) ($billingAccount->primary_workspace_id ?: $workspaceId);
            $billingAccountId = (string) $billingAccount->id;

            $subscription = WorkspaceSubscription::query()
                ->where('billing_account_id', $billingAccountId)
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                $subscription = WorkspaceSubscription::query()
                    ->where('workspace_id', $canonicalWorkspaceId)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $subscription) {
                $subscription = WorkspaceSubscription::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $canonicalWorkspaceId,
                    'billing_account_id' => $billingAccountId,
                    'plan_id' => $accountPlanId,
                    'status' => 'active',
                    'current_period_start' => $now,
                    'current_period_end' => $now->addMonth(),
                    'trial_ends_at' => null,
                    'metadata' => $accountPlanId === 'free'
                        ? ['bootstrap' => true, 'billing_scope' => 'shared_account']
                        : ['bootstrap' => true, 'billing_scope' => 'shared_account', 'last_refill_period_key' => $now->toIso8601String()],
                ]);
            } elseif (! $subscription->billing_account_id) {
                $subscription->billing_account_id = $billingAccountId;
                $subscription->workspace_id = $canonicalWorkspaceId;
                $subscription->save();
            }

            $subscriptionPlanId = $this->normalizePlanId((string) ($subscription->plan_id ?: $accountPlanId));
            $hasStripeSubscription = trim((string) ($subscription->stripe_subscription_id ?? '')) !== '';
            $targetPlanId = $hasStripeSubscription ? $subscriptionPlanId : $accountPlanId;

            if ($targetPlanId === '') {
                $targetPlanId = 'free';
            }

            if ($subscriptionPlanId !== $targetPlanId || $subscription->plan_id !== $targetPlanId) {
                $subscription->plan_id = $targetPlanId;
                if (! $hasStripeSubscription && $targetPlanId !== 'free') {
                    $subscription->trial_ends_at = null;
                }
                $subscription->save();
            }

            if ($accountPlanId !== $targetPlanId || $billingAccount->plan_id !== $targetPlanId) {
                DB::table('billing_accounts')->where('id', $billingAccountId)->update(['plan_id' => $targetPlanId, 'updated_at' => now()]);
                DB::table('workspaces')->where('billing_account_id', $billingAccountId)->update(['plan_id' => $targetPlanId, 'updated_at' => now()]);
                $billingAccount = DB::table('billing_accounts')->where('id', $billingAccountId)->lockForUpdate()->first();
                $accountPlanId = $targetPlanId;
            }

            $plan = $this->resolvePlan($targetPlanId);

            $wallet = WorkspaceCreditWallet::query()
                ->where('billing_account_id', $billingAccountId)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = WorkspaceCreditWallet::query()
                    ->where('workspace_id', $canonicalWorkspaceId)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $wallet) {
                $wallet = WorkspaceCreditWallet::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $canonicalWorkspaceId,
                    'billing_account_id' => $billingAccountId,
                    'scrape_credits_balance' => $targetPlanId === 'free'
                        ? (int) Arr::get($plan, 'trial_scrape_credits', 0)
                        : (int) Arr::get($plan, 'monthly_scrape_credits', 0),
                    'ai_credits_balance' => $targetPlanId === 'free'
                        ? (int) Arr::get($plan, 'trial_ai_credits', 0)
                        : (int) Arr::get($plan, 'monthly_ai_credits', 0),
                    'bonus_scrape_credits' => 0,
                    'bonus_ai_credits' => 0,
                    'lifetime_scrape_used' => 0,
                    'lifetime_ai_used' => 0,
                    'metadata' => [
                        'bootstrap' => true,
                        'billing_scope' => 'shared_account',
                        'welcome_credits_granted_at' => $targetPlanId === 'free' ? $now->toIso8601String() : null,
                    ],
                ]);
            } elseif (! $wallet->billing_account_id) {
                $wallet->billing_account_id = $billingAccountId;
                $wallet->workspace_id = $canonicalWorkspaceId;
                $wallet->save();
            }

            [$subscription, $wallet] = $this->reconcileLocked($workspaceId, $subscription, $wallet, $plan, $now);

            return [$subscription, $wallet, $plan, $billingAccount];
        });
    }

    public function currentPlanId(string $workspaceId): string
    {
        [$subscription] = $this->readWorkspaceBillingSnapshot($workspaceId);

        return $this->normalizePlanId((string) ($subscription->plan_id ?: 'free'));
    }

    public function reconcileWorkspace(string $workspaceId): array
    {
        return $this->summary($workspaceId);
    }

    public function reconcileAllWorkspaces(?int $limit = null): array
    {
        $query = DB::table('workspaces')->select('id')->orderBy('created_at');
        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        $checked = 0;
        $errors = 0;

        foreach ($query->get() as $workspace) {
            try {
                $this->ensureWorkspaceBilling((string) $workspace->id);
                $checked++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        return [
            'checked' => $checked,
            'errors' => $errors,
        ];
    }

    public function grantPlanCycleCredits(string $workspaceId, string $planId, ?CarbonImmutable $periodStart = null, bool $resetBaseBalances = false): void
    {
        DB::transaction(function () use ($workspaceId, $planId, $periodStart, $resetBaseBalances) {
            [$workspace, $billingAccount] = $this->billingAccountForWorkspaceLocked($workspaceId);
            $billingAccountId = (string) $billingAccount->id;
            $canonicalWorkspaceId = (string) ($billingAccount->primary_workspace_id ?: $workspace->id);

            $subscription = WorkspaceSubscription::query()
                ->where('billing_account_id', $billingAccountId)
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                $this->ensureWorkspaceBilling($workspaceId);
                $subscription = WorkspaceSubscription::query()->where('billing_account_id', $billingAccountId)->lockForUpdate()->firstOrFail();
            }

            $wallet = WorkspaceCreditWallet::query()
                ->where('billing_account_id', $billingAccountId)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $this->ensureWorkspaceBilling($workspaceId);
                $wallet = WorkspaceCreditWallet::query()->where('billing_account_id', $billingAccountId)->lockForUpdate()->firstOrFail();
            }

            $normalizedPlanId = $this->normalizePlanId($planId);
            $plan = $this->resolvePlan($normalizedPlanId);
            $periodStart = $periodStart ?: CarbonImmutable::instance($subscription->current_period_start ?: now());
            $periodKey = $periodStart->toIso8601String();
            $metadata = (array) ($subscription->metadata ?? []);

            if (! $resetBaseBalances && ($metadata['last_refill_period_key'] ?? null) === $periodKey) {
                return;
            }

            // Shared-account billing model:
            // plan-cycle credits reset one shared included/base balance per billing account.
            // Purchased/top-up credits live in bonus_* fields and are not touched here.
            $wallet->workspace_id = $canonicalWorkspaceId;
            $wallet->billing_account_id = $billingAccountId;
            $wallet->scrape_credits_balance = (int) Arr::get($plan, 'monthly_scrape_credits', 0);
            $wallet->ai_credits_balance = (int) Arr::get($plan, 'monthly_ai_credits', 0);
            $wallet->save();

            $metadata['last_refill_period_key'] = $periodKey;
            $metadata['last_refill_at'] = now()->toIso8601String();
            $metadata['base_balance_plan_id'] = $normalizedPlanId;
            $metadata['billing_scope'] = 'shared_account';
            $subscription->workspace_id = $canonicalWorkspaceId;
            $subscription->billing_account_id = $billingAccountId;
            $subscription->metadata = $metadata;
            $subscription->plan_id = $normalizedPlanId;
            $subscription->status = $subscription->status === 'trial_expired' ? 'active' : $subscription->status;
            $subscription->save();

            DB::table('billing_accounts')->where('id', $billingAccountId)->update(['plan_id' => $normalizedPlanId, 'updated_at' => now()]);
            DB::table('workspaces')->where('billing_account_id', $billingAccountId)->update(['plan_id' => $normalizedPlanId, 'updated_at' => now()]);

            $this->observability->reportBillingEvent($canonicalWorkspaceId, 'plan_cycle_credits_granted', [
                'plan_id' => $normalizedPlanId,
                'period_key' => $periodKey,
                'reset_base_balances' => $resetBaseBalances,
                'monthly_scrape_credits' => (int) Arr::get($plan, 'monthly_scrape_credits', 0),
                'monthly_ai_credits' => (int) Arr::get($plan, 'monthly_ai_credits', 0),
            ], $billingAccountId, (string) $subscription->id);
        });
    }

    public function applyPurchasedCredits(string $workspaceId, int $scrapeCredits, int $aiCredits, array $purchaseData = []): void
    {
        DB::transaction(function () use ($workspaceId, $scrapeCredits, $aiCredits, $purchaseData) {
            $paymentIntentId = trim((string) Arr::get($purchaseData, 'stripe_payment_intent_id', ''));
            if ($paymentIntentId !== '') {
                $existingPurchase = CreditPurchase::query()
                    ->where('stripe_payment_intent_id', $paymentIntentId)
                    ->lockForUpdate()
                    ->first();

                if ($existingPurchase) {
                    return;
                }
            }

            [$workspace, $billingAccount] = $this->billingAccountForWorkspaceLocked($workspaceId);
            $billingAccountId = (string) $billingAccount->id;
            $canonicalWorkspaceId = (string) ($billingAccount->primary_workspace_id ?: $workspace->id);

            $wallet = WorkspaceCreditWallet::query()
                ->where('billing_account_id', $billingAccountId)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                [, $wallet] = $this->ensureWorkspaceBilling($workspaceId);
                $wallet = WorkspaceCreditWallet::query()->where('billing_account_id', $billingAccountId)->lockForUpdate()->firstOrFail();
            }

            $wallet->bonus_scrape_credits = (int) $wallet->bonus_scrape_credits + max(0, $scrapeCredits);
            $wallet->bonus_ai_credits = (int) $wallet->bonus_ai_credits + max(0, $aiCredits);
            $wallet->save();

            CreditPurchase::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'billing_account_id' => $billingAccountId,
                'credit_package_id' => Arr::get($purchaseData, 'credit_package_id'),
                'stripe_payment_intent_id' => Arr::get($purchaseData, 'stripe_payment_intent_id'),
                'scrape_credits_added' => max(0, $scrapeCredits),
                'ai_credits_added' => max(0, $aiCredits),
                'amount_paid_usd' => (float) Arr::get($purchaseData, 'amount_paid_usd', 0),
                'metadata' => array_merge(Arr::except($purchaseData, ['credit_package_id', 'stripe_payment_intent_id', 'amount_paid_usd']), [
                    'billing_account_id' => $billingAccountId,
                    'billing_scope' => 'shared_account',
                    'canonical_workspace_id' => $canonicalWorkspaceId,
                ]),
            ]);

            $this->observability->reportBillingEvent($workspaceId, 'credits_purchased', [
                'credit_package_id' => Arr::get($purchaseData, 'credit_package_id'),
                'stripe_payment_intent_id' => Arr::get($purchaseData, 'stripe_payment_intent_id'),
                'scrape_credits_added' => max(0, $scrapeCredits),
                'ai_credits_added' => max(0, $aiCredits),
                'amount_paid_usd' => (float) Arr::get($purchaseData, 'amount_paid_usd', 0),
                'canonical_workspace_id' => $canonicalWorkspaceId,
            ], $billingAccountId, (string) Arr::get($purchaseData, 'stripe_payment_intent_id', ''));
        });
    }

    public function customerUsageEstimate(string $workspaceId, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        [$subscription, , $plan] = $this->readWorkspaceBillingSnapshot($workspaceId);
        $planId = $this->normalizePlanId((string) ($subscription->plan_id ?: Arr::get($plan, 'id', 'free')));

        return $this->usageEstimateForQuery(
            WorkspaceUsageEvent::query()->where('workspace_id', $workspaceId),
            $planId,
            $from,
            $to,
        );
    }

    public function billingAccountUsageEstimate(string $billingAccountId, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $subscription = WorkspaceSubscription::query()->where('billing_account_id', $billingAccountId)->first();
        $planId = $this->normalizePlanId((string) ($subscription?->plan_id ?: 'free'));

        return $this->usageEstimateForQuery(
            WorkspaceUsageEvent::query()->where('billing_account_id', $billingAccountId),
            $planId,
            $from,
            $to,
        );
    }

    private function usageEstimateForQuery($usageQuery, string $planId, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $usageQuery->where('status', 'consumed');

        if ($from) {
            $usageQuery->where('consumed_at', '>=', $from);
        }

        if ($to) {
            $usageQuery->where('consumed_at', '<', $to);
        }

        $usage = $usageQuery
            ->selectRaw("
                COALESCE(SUM(CASE WHEN credit_bucket = 'scrape' THEN credit_cost ELSE 0 END), 0) as consumed_scrape_credits,
                COALESCE(SUM(CASE WHEN credit_bucket = 'ai' THEN credit_cost ELSE 0 END), 0) as consumed_ai_credits,
                COALESCE(SUM(provider_cost_usd), 0) as provider_cost_usd_internal
            ")
            ->first();

        $scrapeCredits = (int) ($usage->consumed_scrape_credits ?? 0);
        $aiCredits = (int) ($usage->consumed_ai_credits ?? 0);
        $customerCreditValue = $this->customerCreditValueForPlan($planId);
        $estimatedCreditSpendUsd = $this->estimateCustomerCreditSpendUsd($scrapeCredits, $aiCredits, $customerCreditValue);

        return [
            'consumedScrapeCredits' => $scrapeCredits,
            'consumedAiCredits' => $aiCredits,
            'estimatedCreditSpendUsd' => $estimatedCreditSpendUsd,
            'estimatedOutreachInvestmentUsd' => $estimatedCreditSpendUsd,
            'customerCreditValue' => $customerCreditValue,
        ];
    }

    private function workspaceUsageBreakdown(string $billingAccountId, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $query = WorkspaceUsageEvent::query()
            ->where('workspace_usage_events.billing_account_id', $billingAccountId)
            ->where('workspace_usage_events.status', 'consumed')
            ->leftJoin('workspaces', 'workspaces.id', '=', 'workspace_usage_events.workspace_id')
            ->groupBy('workspace_usage_events.workspace_id', 'workspaces.name')
            ->selectRaw("
                workspace_usage_events.workspace_id as workspace_id,
                COALESCE(workspaces.name, 'Workspace') as workspace_name,
                COALESCE(SUM(CASE WHEN workspace_usage_events.credit_bucket = 'scrape' THEN workspace_usage_events.credit_cost ELSE 0 END), 0) as consumed_scrape_credits,
                COALESCE(SUM(CASE WHEN workspace_usage_events.credit_bucket = 'ai' THEN workspace_usage_events.credit_cost ELSE 0 END), 0) as consumed_ai_credits,
                COALESCE(SUM(workspace_usage_events.provider_cost_usd), 0) as provider_cost_usd_internal
            ");

        if ($from) {
            $query->where('workspace_usage_events.consumed_at', '>=', $from);
        }

        if ($to) {
            $query->where('workspace_usage_events.consumed_at', '<', $to);
        }

        $planId = $this->normalizePlanId((string) (WorkspaceSubscription::query()->where('billing_account_id', $billingAccountId)->value('plan_id') ?: 'free'));
        $customerCreditValue = $this->customerCreditValueForPlan($planId);

        return $query->get()->map(function ($row) use ($customerCreditValue) {
            $scrape = (int) ($row->consumed_scrape_credits ?? 0);
            $ai = (int) ($row->consumed_ai_credits ?? 0);

            return [
                'workspaceId' => (string) $row->workspace_id,
                'workspaceName' => (string) $row->workspace_name,
                'consumedScrapeCredits' => $scrape,
                'consumedAiCredits' => $ai,
                'estimatedCreditSpendUsd' => $this->estimateCustomerCreditSpendUsd($scrape, $ai, $customerCreditValue),
            ];
        })->values()->all();
    }

    public function customerCreditValueForPlan(string $planId): array
    {
        $planId = $this->normalizePlanId($planId);
        $plan = $this->resolvePlan($planId);
        $multiplier = max(0.1, (float) Arr::get($plan, 'topup_price_multiplier', match ($planId) {
            'free' => 1.0,
            'enterprise' => 1.0,
            default => 1.0,
        }));

        $scrapeUsd = max(0, (float) config('outreach.billing.customer_credit_value_usd.scrape', 0.015)) * $multiplier;
        $aiUsd = max(0, (float) config('outreach.billing.customer_credit_value_usd.ai', 0.08)) * $multiplier;

        return [
            'planId' => $planId,
            'planMultiplier' => $multiplier,
            'scrapeUsd' => round($scrapeUsd, 6),
            'aiUsd' => round($aiUsd, 6),
            'source' => 'customer_credit_value_estimate',
        ];
    }

    private function estimateCustomerCreditSpendUsd(int $scrapeCredits, int $aiCredits, array $customerCreditValue): float
    {
        $scrapeValue = (float) ($customerCreditValue['scrapeUsd'] ?? 0);
        $aiValue = (float) ($customerCreditValue['aiUsd'] ?? 0);

        return round(max(0, $scrapeCredits) * $scrapeValue + max(0, $aiCredits) * $aiValue, 2);
    }

    public function getCreditPackageConfig(string $workspaceId, string $packageId): array
    {
        $this->ensureCatalogSeeded();
        [, , $plan] = $this->ensureWorkspaceBilling($workspaceId);

        $package = CreditPackage::query()->find($packageId);
        if (! $package || ! $package->active) {
            throw new RuntimeException('Credit package not found.');
        }

        $allowed = $this->normalizeJsonArray($package->allowed_plan_ids ?? []);
        $currentPlanId = $this->normalizePlanId((string) Arr::get($plan, 'id', 'free'));
        if ($allowed !== [] && ! in_array($currentPlanId, $allowed, true)) {
            throw new RuntimeException('This credit package is not available on the current plan.');
        }

        $multiplier = max(0.1, (float) Arr::get($plan, 'topup_price_multiplier', 1));
        $effectivePriceCents = (int) round(((float) $package->price_usd * 100) * $multiplier);

        return [
            'id' => $package->id,
            'name' => $package->name,
            'scrape_credits' => (int) $package->scrape_credits,
            'ai_credits' => (int) $package->ai_credits,
            'price_cents' => $effectivePriceCents,
            'price_usd' => round($effectivePriceCents / 100, 2),
            'base_price_usd' => round((float) $package->price_usd, 2),
            'plan_id' => $currentPlanId,
            'topup_price_multiplier' => $multiplier,
            'currency' => (string) config('outreach.billing.currency', 'usd'),
        ];
    }

    public function getPlanCheckoutConfig(string $workspaceId, string $planId): array
    {
        $planId = $this->normalizePlanId($planId);
        if ($planId === 'free') {
            throw new RuntimeException('Evaluation access does not require checkout.');
        }

        $plan = $this->resolvePlan($planId);
        if (! $plan || (isset($plan['is_active']) && ! (bool) $plan['is_active'])) {
            throw new RuntimeException('Plan not available for checkout.');
        }

        return [
            'id' => $planId,
            'name' => (string) Arr::get($plan, 'name', ucfirst($planId)),
            'price_cents' => $this->planPriceCents($planId),
            'currency' => (string) config('outreach.billing.currency', 'usd'),
            'monthly_scrape_credits' => (int) Arr::get($plan, 'monthly_scrape_credits', 0),
            'monthly_ai_credits' => (int) Arr::get($plan, 'monthly_ai_credits', 0),
            'workspace_id' => $workspaceId,
        ];
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
        ?float $providerCostReservationUsd = null,
    ): array {
        [$subscription, , , $billingAccount] = $this->ensureWorkspaceBilling($workspaceId);
        $billingAccountId = (string) $billingAccount->id;
        $planIdAtReservation = $this->normalizePlanId((string) ($subscription->plan_id ?: 'free'));
        $periodStart = optional($subscription->current_period_start)?->toIso8601String();
        $periodEnd = optional($subscription->current_period_end)?->toIso8601String();

        if ($this->subscriptionBlocksCreditUsage((string) $subscription->status)) {
            throw new InsufficientCreditsException('Workspace subscription is not active.', [
                'subscriptionStatus' => $subscription->status,
            ]);
        }

        try {
            return DB::transaction(function () use ($workspaceId, $type, $bucket, $units, $creditCost, $provider, $source, $metadata, $providerCostReservationUsd, $subscription, $billingAccountId, $planIdAtReservation, $periodStart, $periodEnd) {
                $this->providerSpend->assertCanReserve($workspaceId, $provider, (float) ($providerCostReservationUsd ?? 0));
                $wallet = WorkspaceCreditWallet::query()
                    ->where('billing_account_id', $billingAccountId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $baseField = $bucket === 'scrape' ? 'scrape_credits_balance' : 'ai_credits_balance';
                $bonusField = $bucket === 'scrape' ? 'bonus_scrape_credits' : 'bonus_ai_credits';
                $baseAvailable = (int) $wallet->{$baseField};
                $bonusAvailable = (int) $wallet->{$bonusField};
                $totalAvailable = $baseAvailable + $bonusAvailable;

                if ($totalAvailable < $creditCost) {
                    throw new InsufficientCreditsException('Not enough credits available for this action.', [
                        'bucket' => $bucket,
                        'required' => $creditCost,
                        'available' => $totalAvailable,
                        'baseAvailable' => $baseAvailable,
                        'bonusAvailable' => $bonusAvailable,
                    ]);
                }

                $deductBase = min($baseAvailable, $creditCost);
                $deductBonus = max(0, $creditCost - $deductBase);

                $wallet->{$baseField} = $baseAvailable - $deductBase;
                $wallet->{$bonusField} = $bonusAvailable - $deductBonus;
                $wallet->save();

                $event = WorkspaceUsageEvent::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'billing_account_id' => $billingAccountId,
                    'type' => $type,
                    'credit_bucket' => $bucket,
                    'units' => $units,
                    'credit_cost' => $creditCost,
                    'provider' => $provider,
                    'provider_cost_usd' => $providerCostReservationUsd,
                    'provider_cost_reserved_usd' => $providerCostReservationUsd,
                    'provider_cost_actual_usd' => null,
                    'source' => $source,
                    'status' => 'reserved',
                    'metadata' => array_merge($metadata, [
                        'billing' => [
                            'billing_account_id' => $billingAccountId,
                            'subscription_id' => $subscription->id,
                            'plan_id_at_charge' => $planIdAtReservation,
                            'period_start' => $periodStart,
                            'period_end' => $periodEnd,
                            'credit_model' => 'monthly_reset_plus_bonus_topups',
                            'customer_billing_unit' => 'credits',
                        ],
                        'deductions' => [
                            'base' => $deductBase,
                            'bonus' => $deductBonus,
                        ],
                    ]),
                ]);

                $this->observability->reportBillingEvent($workspaceId, 'credits_reserved', [
                    'usage_event_id' => (string) $event->id,
                    'credit_bucket' => $bucket,
                    'credit_cost' => $creditCost,
                    'units' => $units,
                    'provider' => $provider,
                    'source' => $source,
                    'remaining_balance' => (int) $wallet->{$baseField} + (int) $wallet->{$bonusField},
                ], $billingAccountId, (string) $event->id);

                return [
                    'usage_event_id' => $event->id,
                    'credit_bucket' => $bucket,
                    'credit_cost' => $creditCost,
                    'units' => $units,
                    'remaining_balance' => (int) $wallet->{$baseField} + (int) $wallet->{$bonusField},
                ];
            });
        } catch (ProviderSpendLimitException $exception) {
            $this->providerSpend->recordBlock($exception, [
                'type' => $type,
                'source' => $source,
                'units' => $units,
            ]);

            throw $exception;
        }
    }

    private function billingAccountForWorkspaceLocked(string $workspaceId): array
    {
        $workspace = DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
        if (! $workspace) {
            throw new RuntimeException('Workspace not found for billing.');
        }

        if (! Schema::hasTable('billing_accounts') || ! Schema::hasColumn('workspaces', 'billing_account_id')) {
            $fallback = (object) [
                'id' => $workspace->id,
                'owner_user_id' => (string) ($workspace->owner_id ?: 'legacy'),
                'primary_workspace_id' => $workspace->id,
                'name' => (string) ($workspace->name ?: 'Workspace billing'),
                'plan_id' => (string) ($workspace->plan_id ?: 'free'),
                'status' => 'active',
                'metadata' => [],
            ];

            return [$workspace, $fallback];
        }

        $accountId = trim((string) ($workspace->billing_account_id ?? ''));
        if ($accountId !== '') {
            $account = DB::table('billing_accounts')->where('id', $accountId)->lockForUpdate()->first();
            if ($account) {
                return [$workspace, $account];
            }
        }

        $ownerUserId = trim((string) ($workspace->owner_id ?? '')) ?: 'workspace:'.$workspaceId;
        $account = DB::table('billing_accounts')->where('owner_user_id', $ownerUserId)->lockForUpdate()->first();

        if (! $account) {
            $accountId = (string) Str::uuid();
            DB::table('billing_accounts')->insert([
                'id' => $accountId,
                'owner_user_id' => $ownerUserId,
                'primary_workspace_id' => $workspaceId,
                'name' => ((string) ($workspace->name ?? 'SocialCore')).' billing',
                'plan_id' => $this->normalizePlanId((string) ($workspace->plan_id ?? 'free')),
                'status' => 'active',
                'metadata' => json_encode([
                    'bootstrap' => true,
                    'free_welcome_credits_account_scoped' => true,
                    'created_from_workspace_id' => $workspaceId,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $account = DB::table('billing_accounts')->where('id', $accountId)->lockForUpdate()->first();
        }

        DB::table('workspaces')->where('id', $workspaceId)->update([
            'billing_account_id' => $account->id,
            'plan_id' => $account->plan_id ?: ($workspace->plan_id ?: 'free'),
            'updated_at' => now(),
        ]);

        if (! $account->primary_workspace_id) {
            DB::table('billing_accounts')->where('id', $account->id)->update(['primary_workspace_id' => $workspaceId, 'updated_at' => now()]);
            $account = DB::table('billing_accounts')->where('id', $account->id)->lockForUpdate()->first();
        }

        $workspace = DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();

        return [$workspace, $account];
    }

    private function reconcileLocked(string $workspaceId, WorkspaceSubscription $subscription, WorkspaceCreditWallet $wallet, array $plan, CarbonImmutable $now): array
    {
        $planId = $this->normalizePlanId((string) ($subscription->plan_id ?: Arr::get($plan, 'id', 'free')));
        $metadata = (array) ($subscription->metadata ?? []);
        $subscriptionChanged = false;
        $walletChanged = false;

        if ($planId === 'free') {
            if ($subscription->trial_ends_at !== null) {
                $subscription->trial_ends_at = null;
                $subscriptionChanged = true;
            }
            if (in_array($subscription->status, ['', 'trialing', 'trial_expired'], true)) {
                $subscription->status = 'active';
                $subscriptionChanged = true;
            }
        } else {
            if (! $subscription->current_period_start) {
                $subscription->current_period_start = $now;
                $subscriptionChanged = true;
            }
            if (! $subscription->current_period_end) {
                $subscription->current_period_end = CarbonImmutable::instance($subscription->current_period_start ?: $now)->addMonth();
                $subscriptionChanged = true;
            }
            if (in_array($subscription->status, ['', 'trialing', 'trial_expired'], true)) {
                $subscription->status = 'active';
                $subscriptionChanged = true;
            }

            $currentPeriodStart = CarbonImmutable::instance($subscription->current_period_start ?: $now);
            $currentPeriodEnd = CarbonImmutable::instance($subscription->current_period_end ?: $now->addMonth());
            $currentPeriodKey = $currentPeriodStart->toIso8601String();
            $lastRefillKey = (string) ($metadata['last_refill_period_key'] ?? '');
            $baseBalancePlanId = (string) ($metadata['base_balance_plan_id'] ?? '');
            $monthlyScrapeCredits = (int) Arr::get($plan, 'monthly_scrape_credits', 0);
            $monthlyAiCredits = (int) Arr::get($plan, 'monthly_ai_credits', 0);
            $baseBalanceResetThisPass = false;

            if ($baseBalancePlanId !== $planId) {
                $wallet->scrape_credits_balance = $monthlyScrapeCredits;
                $wallet->ai_credits_balance = $monthlyAiCredits;
                $metadata['base_balance_plan_id'] = $planId;
                $metadata['last_refill_period_key'] = $currentPeriodKey;
                $metadata['last_refill_at'] = $now->toIso8601String();
                $walletChanged = true;
                $subscriptionChanged = true;
                $lastRefillKey = $currentPeriodKey;
                $baseBalanceResetThisPass = true;
            }

            if ($lastRefillKey === '') {
                $wallet->scrape_credits_balance = $monthlyScrapeCredits;
                $wallet->ai_credits_balance = $monthlyAiCredits;
                $metadata['last_refill_period_key'] = $currentPeriodKey;
                $metadata['base_balance_plan_id'] = $planId;
                $walletChanged = true;
                $subscriptionChanged = true;
                $baseBalanceResetThisPass = true;
            }

            if (! $this->subscriptionCanUseIncludedCredits((string) $subscription->status)) {
                if ((int) $wallet->scrape_credits_balance !== 0) {
                    $wallet->scrape_credits_balance = 0;
                    $walletChanged = true;
                }
                if ((int) $wallet->ai_credits_balance !== 0) {
                    $wallet->ai_credits_balance = 0;
                    $walletChanged = true;
                }
            } elseif ($currentPeriodKey !== $lastRefillKey) {
                $wallet->scrape_credits_balance = $monthlyScrapeCredits;
                $wallet->ai_credits_balance = $monthlyAiCredits;
                $metadata['last_refill_period_key'] = $currentPeriodKey;
                $metadata['last_refill_at'] = $now->toIso8601String();
                $metadata['base_balance_plan_id'] = $planId;
                $walletChanged = true;
                $subscriptionChanged = true;
                $baseBalanceResetThisPass = true;
            }

            $safety = 0;
            while ($this->subscriptionCanUseIncludedCredits((string) $subscription->status) && $now->greaterThanOrEqualTo($currentPeriodEnd) && $safety < 24) {
                $currentPeriodStart = $currentPeriodEnd;
                $currentPeriodEnd = $currentPeriodEnd->addMonth();
                $wallet->scrape_credits_balance = $monthlyScrapeCredits;
                $wallet->ai_credits_balance = $monthlyAiCredits;
                $metadata['last_refill_period_key'] = $currentPeriodStart->toIso8601String();
                $metadata['last_refill_at'] = $now->toIso8601String();
                $subscription->current_period_start = $currentPeriodStart;
                $subscription->current_period_end = $currentPeriodEnd;
                $walletChanged = true;
                $subscriptionChanged = true;
                $baseBalanceResetThisPass = true;
                $safety++;
            }

            if (! $baseBalanceResetThisPass) {
                $baseDeductions = $this->currentPeriodBaseDeductions($workspaceId, $currentPeriodStart, $currentPeriodEnd);
                $expectedScrapeBaseBalance = max(0, $monthlyScrapeCredits - $baseDeductions['scrape']);
                $expectedAiBaseBalance = max(0, $monthlyAiCredits - $baseDeductions['ai']);

                if ((int) $wallet->scrape_credits_balance > $expectedScrapeBaseBalance) {
                    $wallet->scrape_credits_balance = $expectedScrapeBaseBalance;
                    $walletChanged = true;
                }

                if ((int) $wallet->ai_credits_balance > $expectedAiBaseBalance) {
                    $wallet->ai_credits_balance = $expectedAiBaseBalance;
                    $walletChanged = true;
                }
            }
        }

        if ($subscriptionChanged) {
            $subscription->metadata = $metadata;
            $subscription->save();
        }
        if ($walletChanged) {
            $wallet->save();
        }

        return [$subscription->fresh(), $wallet->fresh()];
    }

    private function currentPeriodBaseDeductions(string $workspaceId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $deductions = [
            'scrape' => 0,
            'ai' => 0,
        ];

        WorkspaceUsageEvent::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['reserved', 'consumed'])
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEnd)
            ->get(['credit_bucket', 'credit_cost', 'metadata'])
            ->each(function (WorkspaceUsageEvent $event) use (&$deductions) {
                $bucket = $event->credit_bucket === 'ai' ? 'ai' : 'scrape';
                $metadata = (array) ($event->metadata ?? []);
                $baseDeduction = data_get($metadata, 'deductions.base');

                if ($baseDeduction === null) {
                    $baseDeduction = $event->credit_cost;
                }

                $deductions[$bucket] += max(0, (int) $baseDeduction);
            });

        return $deductions;
    }

    private function ensureCatalogSeeded(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('credit_packages')) {
            return;
        }

        if (CreditPackage::query()->where('active', true)->exists()) {
            return;
        }

        foreach ($this->defaultCreditPackages() as $package) {
            DB::table('credit_packages')->updateOrInsert(
                ['id' => $package['id']],
                [
                    'name' => $package['name'],
                    'scrape_credits' => $package['scrape_credits'],
                    'ai_credits' => $package['ai_credits'],
                    'price_usd' => $package['price_usd'],
                    'allowed_plan_ids' => json_encode($package['allowed_plan_ids']),
                    'active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function defaultCreditPackages(): array
    {
        $configured = config('outreach.billing.credit_packages');
        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return self::DEFAULT_CREDIT_PACKAGES;
    }

    private function publicPlanFeatures(string $planId, array $features): array
    {
        return match ($planId) {
            'free' => ['one-time workflow evaluation', '100 discovery results', '20 profile enrichments', '5 AI drafts'],
            'pro' => ['monthly discovery and enrichment capacity', 'up to 5,000 discovery results', 'up to 1,250 review-ready profiles', '250 AI drafts or follow-ups', 'team workspace'],
            'enterprise' => ['shared capacity across client workspaces', 'up to 10,000 discovery results', 'up to 2,500 review-ready profiles', '800 AI drafts or follow-ups', 'priority support'],
            default => $features,
        };
    }

    private function maxScraperDepth(array $modules): string
    {
        $depths = array_map(fn (array $module) => strtolower((string) ($module['depth'] ?? 'basic')), $modules);
        if (in_array('deep', $depths, true)) {
            return 'deep';
        }
        if (in_array('standard', $depths, true)) {
            return 'standard';
        }

        return 'basic';
    }

    private function planPriceCents(string $planId): int
    {
        $configured = config("outreach.billing.plan_prices.{$planId}");
        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return self::DEFAULT_PLAN_PRICES_CENTS[$planId] ?? 0;
    }

    private function estimateApifyCredits(?string $moduleKey, ?string $actorKey, ?string $actorId, array $input): array
    {
        return $this->scrapers->estimateCredits($moduleKey, $actorKey, $actorId, $input);
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
        $seedCount = 1;
        foreach (['hashtags', 'searchTerms', 'queries', 'keywords'] as $seedKey) {
            $seedValue = $input[$seedKey] ?? null;
            if (is_array($seedValue)) {
                $nonEmptySeeds = array_filter($seedValue, fn ($value) => trim((string) $value) !== '');
                if (count($nonEmptySeeds) > 0) {
                    $seedCount = max($seedCount, count($nonEmptySeeds));
                }
            }
        }

        foreach (['resultsLimit', 'results_limit', 'maxItems', 'max_items', 'limit'] as $key) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $perSeedLimit = max(1, min(1000, (int) $input[$key]));

                return max(1, min(5000, $perSeedLimit * $seedCount));
            }
        }

        foreach (['hashtags', 'searchTerms', 'queries', 'keywords'] as $key) {
            $value = $input[$key] ?? null;
            if (is_array($value) && count($value) > 0) {
                return max(1, min(5000, count(array_filter($value, fn ($seed) => trim((string) $seed) !== '')) * 25));
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
            'trial_scrape_credits' => 25,
            'trial_ai_credits' => 5,
            'topup_price_multiplier' => 1.0,
        ];
    }

    private function planState(string $workspaceId, WorkspaceSubscription $subscription, string $currentPlanId): array
    {
        $workspacePlanId = $this->normalizePlanId((string) (DB::table('workspaces')->where('id', $workspaceId)->value('plan_id') ?? 'free'));
        $subscriptionPlanId = $this->normalizePlanId((string) ($subscription->plan_id ?: 'free'));

        return [
            'effectivePlanId' => $currentPlanId,
            'workspacePlanId' => $workspacePlanId,
            'subscriptionPlanId' => $subscriptionPlanId,
            'hasPlanMismatch' => $workspacePlanId !== $subscriptionPlanId || $subscriptionPlanId !== $currentPlanId,
            'status' => (string) $subscription->status,
            'trialEndsAt' => optional($subscription->trial_ends_at)?->toIso8601String(),
        ];
    }

    private function normalizePlanId(string $planId): string
    {
        $planId = strtolower(trim($planId));

        return self::PLAN_ALIASES[$planId] ?? ($planId !== '' ? $planId : 'free');
    }

    private function subscriptionCanUseIncludedCredits(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::CREDIT_ELIGIBLE_SUBSCRIPTION_STATUSES, true);
    }

    private function subscriptionBlocksCreditUsage(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::CREDIT_BLOCKED_SUBSCRIPTION_STATUSES, true);
    }

    private function normalizeJsonArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, fn ($item) => is_scalar($item) && trim((string) $item) !== ''));
            }
        }

        if (is_array($value)) {
            return array_values(array_filter($value, fn ($item) => is_scalar($item) && trim((string) $item) !== ''));
        }

        return [];
    }
}
