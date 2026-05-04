<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditPackage;
use App\Models\CreditPurchase;
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

    private const DEFAULT_PLAN_PRICES_CENTS = [
        'free' => 0,
        'pro' => 4900,
        'enterprise' => 14900,
    ];

    private const DEFAULT_CREDIT_PACKAGES = [
        [
            'id' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Starter Top-up',
            'scrape_credits' => 500,
            'ai_credits' => 50,
            'price_usd' => 19.00,
            'allowed_plan_ids' => ['free', 'pro', 'enterprise'],
        ],
        [
            'id' => '22222222-2222-4222-8222-222222222222',
            'name' => 'Growth Top-up',
            'scrape_credits' => 2000,
            'ai_credits' => 250,
            'price_usd' => 69.00,
            'allowed_plan_ids' => ['free', 'pro', 'enterprise'],
        ],
        [
            'id' => '33333333-3333-4333-8333-333333333333',
            'name' => 'Scale Top-up',
            'scrape_credits' => 6000,
            'ai_credits' => 800,
            'price_usd' => 179.00,
            'allowed_plan_ids' => ['pro', 'enterprise'],
        ],
    ];


    public function __construct(
        private ScraperRegistryService $scrapers,
    ) {
    }

    public function summary(string $workspaceId): array
    {
        [$subscription, $wallet, $plan] = $this->ensureWorkspaceBilling($workspaceId);

        $usage = WorkspaceUsageEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'consumed')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN credit_bucket = 'scrape' THEN credit_cost ELSE 0 END), 0) as consumed_scrape_credits,
                COALESCE(SUM(CASE WHEN credit_bucket = 'ai' THEN credit_cost ELSE 0 END), 0) as consumed_ai_credits,
                COALESCE(SUM(provider_cost_usd), 0) as provider_spend_usd
            ")
            ->first();

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
                'totalScrapeCreditsAvailable' => (int) $wallet->scrape_credits_balance + (int) $wallet->bonus_scrape_credits,
                'totalAiCreditsAvailable' => (int) $wallet->ai_credits_balance + (int) $wallet->bonus_ai_credits,
                'lifetimeScrapeUsed' => (int) $wallet->lifetime_scrape_used,
                'lifetimeAiUsed' => (int) $wallet->lifetime_ai_used,
            ],
            'usage' => [
                'consumedScrapeCredits' => (int) ($usage->consumed_scrape_credits ?? 0),
                'consumedAiCredits' => (int) ($usage->consumed_ai_credits ?? 0),
                'providerSpendUsd' => round((float) ($usage->provider_spend_usd ?? 0), 4),
            ],
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
        [$subscription, $wallet, $currentPlan] = $this->ensureWorkspaceBilling($workspaceId);

        $plans = DB::table('plans')
            ->where('is_active', true)
            ->orderByRaw("CASE id WHEN 'free' THEN 1 WHEN 'pro' THEN 2 WHEN 'enterprise' THEN 3 ELSE 4 END")
            ->get()
            ->map(function ($row) use ($subscription) {
                $data = (array) $row;
                $planId = (string) ($data['id'] ?? 'free');
                $features = $this->normalizeJsonArray($data['features'] ?? []);
                $priceCents = $this->planPriceCents($planId);
                $scraperModules = $this->scrapers->availableForPlan($planId);

                return [
                    'id' => $planId,
                    'name' => (string) ($data['name'] ?? ''),
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
                    'isCurrent' => $planId === (string) $subscription->plan_id,
                ];
            })
            ->values()
            ->all();

        $multiplier = max(0.1, (float) Arr::get($currentPlan, 'topup_price_multiplier', 1));
        $currentPlanId = (string) ($subscription->plan_id ?: 'free');

        $packages = CreditPackage::query()
            ->where('active', true)
            ->orderBy('price_usd')
            ->get()
            ->map(function (CreditPackage $package) use ($multiplier, $currentPlanId) {
                $allowed = $this->normalizeJsonArray($package->allowed_plan_ids ?? []);
                if ($allowed !== [] && !in_array($currentPlanId, $allowed, true)) {
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
            'plans' => $plans,
            'packages' => $packages,
        ];
    }

    public function reserveApify(?string $workspaceId, ?string $moduleKey, ?string $actorKey, ?string $actorId, array $input, ?float $maxChargeUsd = null): array
    {
        if (!$workspaceId) {
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
            $event->error_message = $reason;
            $event->metadata = array_merge((array) ($event->metadata ?? []), $metadata);
            $event->refunded_at = now();
            $event->save();
        });
    }

    public function ensureWorkspaceBilling(string $workspaceId): array
    {
        $this->ensureCatalogSeeded();

        return DB::transaction(function () use ($workspaceId) {
            $workspace = DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
            if (!$workspace) {
                throw new RuntimeException('Workspace not found for billing.');
            }

            $workspacePlanId = $this->normalizePlanId((string) ($workspace->plan_id ?? 'free'));
            $plan = $this->resolvePlan($workspacePlanId);
            $now = CarbonImmutable::now();

            $subscription = WorkspaceSubscription::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                $subscription = WorkspaceSubscription::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'plan_id' => $workspacePlanId,
                    'status' => $workspacePlanId === 'free' ? 'trialing' : 'active',
                    'current_period_start' => $now,
                    'current_period_end' => $now->addMonth(),
                    'trial_ends_at' => $workspacePlanId === 'free' ? $now->addDays((int) config('outreach.billing.trial_days', 14)) : null,
                    'metadata' => $workspacePlanId === 'free'
                        ? ['bootstrap' => true]
                        : ['bootstrap' => true, 'last_refill_period_key' => $now->toIso8601String()],
                ]);
            }

            $subscriptionPlanId = $this->normalizePlanId((string) ($subscription->plan_id ?: $workspacePlanId));
            if ($subscription->plan_id !== $subscriptionPlanId) {
                $subscription->plan_id = $subscriptionPlanId;
                $subscription->save();
            }
            if ($workspacePlanId !== $subscriptionPlanId) {
                DB::table('workspaces')->where('id', $workspaceId)->update(['plan_id' => $subscriptionPlanId]);
                $workspacePlanId = $subscriptionPlanId;
            }
            $plan = $this->resolvePlan($subscriptionPlanId);

            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $wallet = WorkspaceCreditWallet::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'scrape_credits_balance' => $subscriptionPlanId === 'free'
                        ? (int) Arr::get($plan, 'trial_scrape_credits', 0)
                        : (int) Arr::get($plan, 'monthly_scrape_credits', 0),
                    'ai_credits_balance' => $subscriptionPlanId === 'free'
                        ? (int) Arr::get($plan, 'trial_ai_credits', 0)
                        : (int) Arr::get($plan, 'monthly_ai_credits', 0),
                    'bonus_scrape_credits' => 0,
                    'bonus_ai_credits' => 0,
                    'lifetime_scrape_used' => 0,
                    'lifetime_ai_used' => 0,
                    'metadata' => ['bootstrap' => true],
                ]);
            }

            [$subscription, $wallet] = $this->reconcileLocked($workspaceId, $subscription, $wallet, $plan, $now);

            return [$subscription, $wallet, $plan];
        });
    }


    public function currentPlanId(string $workspaceId): string
    {
        [$subscription] = $this->ensureWorkspaceBilling($workspaceId);

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
            $subscription = WorkspaceSubscription::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->firstOrFail();

            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->firstOrFail();

            $normalizedPlanId = $this->normalizePlanId($planId);
            $plan = $this->resolvePlan($normalizedPlanId);
            $periodStart = $periodStart ?: CarbonImmutable::instance($subscription->current_period_start ?: now());
            $periodKey = $periodStart->toIso8601String();
            $metadata = (array) ($subscription->metadata ?? []);

            if (!$resetBaseBalances && ($metadata['last_refill_period_key'] ?? null) === $periodKey) {
                return;
            }

            if ($resetBaseBalances) {
                $wallet->scrape_credits_balance = (int) Arr::get($plan, 'monthly_scrape_credits', 0);
                $wallet->ai_credits_balance = (int) Arr::get($plan, 'monthly_ai_credits', 0);
            } else {
                $wallet->scrape_credits_balance = (int) $wallet->scrape_credits_balance + (int) Arr::get($plan, 'monthly_scrape_credits', 0);
                $wallet->ai_credits_balance = (int) $wallet->ai_credits_balance + (int) Arr::get($plan, 'monthly_ai_credits', 0);
            }
            $wallet->save();

            $metadata['last_refill_period_key'] = $periodKey;
            $metadata['last_refill_at'] = now()->toIso8601String();
            $metadata['base_balance_plan_id'] = $normalizedPlanId;
            $subscription->metadata = $metadata;
            $subscription->plan_id = $normalizedPlanId;
            $subscription->status = $subscription->status === 'trial_expired' ? 'active' : $subscription->status;
            $subscription->save();
        });
    }

    public function applyPurchasedCredits(string $workspaceId, int $scrapeCredits, int $aiCredits, array $purchaseData = []): void
    {
        DB::transaction(function () use ($workspaceId, $scrapeCredits, $aiCredits, $purchaseData) {
            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                [, $wallet] = $this->ensureWorkspaceBilling($workspaceId);
                $wallet = WorkspaceCreditWallet::query()->where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
            }

            $wallet->bonus_scrape_credits = (int) $wallet->bonus_scrape_credits + max(0, $scrapeCredits);
            $wallet->bonus_ai_credits = (int) $wallet->bonus_ai_credits + max(0, $aiCredits);
            $wallet->save();

            CreditPurchase::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'credit_package_id' => Arr::get($purchaseData, 'credit_package_id'),
                'stripe_payment_intent_id' => Arr::get($purchaseData, 'stripe_payment_intent_id'),
                'scrape_credits_added' => max(0, $scrapeCredits),
                'ai_credits_added' => max(0, $aiCredits),
                'amount_paid_usd' => (float) Arr::get($purchaseData, 'amount_paid_usd', 0),
                'metadata' => Arr::except($purchaseData, ['credit_package_id', 'stripe_payment_intent_id', 'amount_paid_usd']),
            ]);
        });
    }

    public function getCreditPackageConfig(string $workspaceId, string $packageId): array
    {
        $this->ensureCatalogSeeded();
        [, , $plan] = $this->ensureWorkspaceBilling($workspaceId);

        $package = CreditPackage::query()->find($packageId);
        if (!$package || !$package->active) {
            throw new RuntimeException('Credit package not found.');
        }

        $allowed = $this->normalizeJsonArray($package->allowed_plan_ids ?? []);
        $currentPlanId = $this->normalizePlanId((string) Arr::get($plan, 'id', 'free'));
        if ($allowed !== [] && !in_array($currentPlanId, $allowed, true)) {
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
            'currency' => (string) config('outreach.billing.currency', 'usd'),
        ];
    }

    public function getPlanCheckoutConfig(string $workspaceId, string $planId): array
    {
        $planId = $this->normalizePlanId($planId);
        if ($planId === 'free') {
            throw new RuntimeException('Free plan does not require checkout.');
        }

        $plan = $this->resolvePlan($planId);
        if (!$plan || (isset($plan['is_active']) && !(bool) $plan['is_active'])) {
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
    ): array {
        [$subscription] = $this->ensureWorkspaceBilling($workspaceId);

        if (in_array($subscription->status, ['past_due', 'unpaid', 'incomplete_expired'], true)) {
            throw new InsufficientCreditsException('Workspace subscription is not active.', [
                'subscriptionStatus' => $subscription->status,
            ]);
        }

        return DB::transaction(function () use ($workspaceId, $type, $bucket, $units, $creditCost, $provider, $source, $metadata) {
            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $workspaceId)
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
                'type' => $type,
                'credit_bucket' => $bucket,
                'units' => $units,
                'credit_cost' => $creditCost,
                'provider' => $provider,
                'source' => $source,
                'status' => 'reserved',
                'metadata' => array_merge($metadata, [
                    'deductions' => [
                        'base' => $deductBase,
                        'bonus' => $deductBonus,
                    ],
                ]),
            ]);

            return [
                'usage_event_id' => $event->id,
                'credit_bucket' => $bucket,
                'credit_cost' => $creditCost,
                'units' => $units,
                'remaining_balance' => (int) $wallet->{$baseField} + (int) $wallet->{$bonusField},
            ];
        });
    }

    private function reconcileLocked(string $workspaceId, WorkspaceSubscription $subscription, WorkspaceCreditWallet $wallet, array $plan, CarbonImmutable $now): array
    {
        $planId = $this->normalizePlanId((string) ($subscription->plan_id ?: Arr::get($plan, 'id', 'free')));
        $metadata = (array) ($subscription->metadata ?? []);
        $subscriptionChanged = false;
        $walletChanged = false;

        if ($planId === 'free') {
            if (!$subscription->trial_ends_at) {
                $subscription->trial_ends_at = $now->addDays((int) config('outreach.billing.trial_days', 14));
                $subscriptionChanged = true;
            }
            if ($subscription->status === '' || $subscription->status === 'active') {
                $subscription->status = 'trialing';
                $subscriptionChanged = true;
            }

            $trialEndsAt = $subscription->trial_ends_at ? CarbonImmutable::instance($subscription->trial_ends_at) : null;
            if ($trialEndsAt && $now->greaterThanOrEqualTo($trialEndsAt) && $subscription->status !== 'trial_expired') {
                $subscription->status = 'trial_expired';
                $wallet->scrape_credits_balance = 0;
                $wallet->ai_credits_balance = 0;
                $metadata['trial_expired_at'] = $now->toIso8601String();
                $subscriptionChanged = true;
                $walletChanged = true;
            }
        } else {
            if (!$subscription->current_period_start) {
                $subscription->current_period_start = $now;
                $subscriptionChanged = true;
            }
            if (!$subscription->current_period_end) {
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

            if ($baseBalancePlanId !== $planId) {
                $wallet->scrape_credits_balance = (int) Arr::get($plan, 'monthly_scrape_credits', 0);
                $wallet->ai_credits_balance = (int) Arr::get($plan, 'monthly_ai_credits', 0);
                $metadata['base_balance_plan_id'] = $planId;
                $metadata['last_refill_period_key'] = $currentPeriodKey;
                $metadata['last_refill_at'] = $now->toIso8601String();
                $walletChanged = true;
                $subscriptionChanged = true;
                $lastRefillKey = $currentPeriodKey;
            }

            if ($lastRefillKey === '') {
                $metadata['last_refill_period_key'] = $currentPeriodKey;
                $metadata['base_balance_plan_id'] = $planId;
                $subscriptionChanged = true;
            } elseif (!in_array($subscription->status, ['past_due', 'unpaid', 'incomplete_expired'], true) && $currentPeriodKey !== $lastRefillKey) {
                $wallet->scrape_credits_balance = (int) $wallet->scrape_credits_balance + (int) Arr::get($plan, 'monthly_scrape_credits', 0);
                $wallet->ai_credits_balance = (int) $wallet->ai_credits_balance + (int) Arr::get($plan, 'monthly_ai_credits', 0);
                $metadata['last_refill_period_key'] = $currentPeriodKey;
                $metadata['last_refill_at'] = $now->toIso8601String();
                $metadata['base_balance_plan_id'] = $planId;
                $walletChanged = true;
                $subscriptionChanged = true;
            }

            $safety = 0;
            while (!in_array($subscription->status, ['past_due', 'unpaid', 'incomplete_expired'], true) && $now->greaterThanOrEqualTo($currentPeriodEnd) && $safety < 24) {
                $currentPeriodStart = $currentPeriodEnd;
                $currentPeriodEnd = $currentPeriodEnd->addMonth();
                $wallet->scrape_credits_balance = (int) $wallet->scrape_credits_balance + (int) Arr::get($plan, 'monthly_scrape_credits', 0);
                $wallet->ai_credits_balance = (int) $wallet->ai_credits_balance + (int) Arr::get($plan, 'monthly_ai_credits', 0);
                $metadata['last_refill_period_key'] = $currentPeriodStart->toIso8601String();
                $metadata['last_refill_at'] = $now->toIso8601String();
                $subscription->current_period_start = $currentPeriodStart;
                $subscription->current_period_end = $currentPeriodEnd;
                $walletChanged = true;
                $subscriptionChanged = true;
                $safety++;
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

    private function ensureCatalogSeeded(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('credit_packages')) {
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
