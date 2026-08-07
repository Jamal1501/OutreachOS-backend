<?php

namespace App\Services;

use App\Models\CreditPurchase;
use App\Models\WorkspaceSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class StripeBillingService
{
    public function __construct(
        private WorkspaceBillingService $billing,
        private ObservabilityService $observability,
    ) {}

    public function createSubscriptionCheckoutSession(
        string $workspaceId,
        string $planId,
        string $successUrl,
        string $cancelUrl
    ): array {
        $config = $this->billing->getPlanCheckoutConfig($workspaceId, $planId);
        [$currentSubscription] = $this->billing->ensureWorkspaceBilling($workspaceId);
        $billingAccountId = (string) ($currentSubscription->billing_account_id ?: '');
        $customerId = $this->ensureStripeCustomer($workspaceId);
        $checkoutIntentToken = (string) Str::uuid();
        $checkoutReservationExpiresAt = now()->addHours(24);

        DB::transaction(function () use ($currentSubscription, $config, $checkoutIntentToken, $checkoutReservationExpiresAt): void {
            $record = WorkspaceSubscription::query()->where('id', $currentSubscription->id)->lockForUpdate()->firstOrFail();
            if ($this->subscriptionIsManagedInStripe($record)) {
                throw new RuntimeException('active_subscription_exists');
            }

            $metadata = (array) ($record->metadata ?? []);
            $pendingCheckout = (array) ($metadata['pending_subscription_checkout'] ?? []);
            $pendingExpiresAt = isset($pendingCheckout['expires_at'])
                ? CarbonImmutable::parse((string) $pendingCheckout['expires_at'])
                : null;
            if ($pendingExpiresAt?->isFuture()) {
                throw new RuntimeException('subscription_checkout_pending');
            }

            $metadata['pending_subscription_checkout'] = [
                'intent_token' => $checkoutIntentToken,
                'status' => 'creating',
                'id' => null,
                'url' => null,
                'plan_id' => $config['id'],
                'expires_at' => $checkoutReservationExpiresAt->toIso8601String(),
                'created_at' => now()->toIso8601String(),
            ];
            $record->metadata = $metadata;
            $record->save();
        });

        $trialDays = 0;

        $payload = [
            'customer' => $customerId,
            'client_reference_id' => $workspaceId,
            'mode' => 'subscription',
            'payment_method_collection' => 'always',
            'allow_promotion_codes' => 'true',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'billing_type' => 'subscription_checkout',
                'workspace_id' => $workspaceId,
                'billing_account_id' => $billingAccountId,
                'plan_id' => $config['id'],
                'checkout_intent_token' => $checkoutIntentToken,
            ],
            'subscription_data' => [
                'metadata' => [
                    'workspace_id' => $workspaceId,
                    'billing_account_id' => $billingAccountId,
                    'plan_id' => $config['id'],
                    'checkout_intent_token' => $checkoutIntentToken,
                ],
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $config['currency'],
                    'unit_amount' => $config['price_cents'],
                    'recurring' => ['interval' => 'month'],
                    'product_data' => [
                        'name' => 'Social CORE '.($config['id'] === 'enterprise' ? 'Agency' : $config['name']),
                        'description' => sprintf(
                            'Monthly discovery, enrichment and outreach capacity (%d workflow credits and %d AI drafts).',
                            $config['monthly_scrape_credits'],
                            $config['monthly_ai_credits'],
                        ),
                    ],
                ],
            ]],
        ];

        $idempotencyKey = 'subscription-checkout-'.hash('sha256', $checkoutIntentToken);
        $response = $this->request('POST', '/checkout/sessions', $payload, $idempotencyKey);
        $checkoutExpiresAt = isset($response['expires_at'])
            ? CarbonImmutable::createFromTimestampUTC((int) $response['expires_at'])
            : now()->addHours(23);

        DB::transaction(function () use ($currentSubscription, $response, $config, $checkoutExpiresAt, $checkoutIntentToken): void {
            $record = WorkspaceSubscription::query()->where('id', $currentSubscription->id)->lockForUpdate()->firstOrFail();
            if ($this->subscriptionIsManagedInStripe($record)) {
                throw new RuntimeException('active_subscription_exists');
            }
            $metadata = (array) ($record->metadata ?? []);
            $pendingCheckout = (array) ($metadata['pending_subscription_checkout'] ?? []);
            if (($pendingCheckout['intent_token'] ?? null) !== $checkoutIntentToken) {
                throw new RuntimeException('subscription_checkout_reservation_lost');
            }
            $metadata['pending_subscription_checkout'] = [
                'intent_token' => $checkoutIntentToken,
                'status' => 'ready',
                'id' => (string) ($response['id'] ?? ''),
                'url' => (string) ($response['url'] ?? ''),
                'plan_id' => $config['id'],
                'expires_at' => $checkoutExpiresAt->toIso8601String(),
                'created_at' => now()->toIso8601String(),
            ];
            $record->metadata = $metadata;
            $record->save();
        });
        $this->observability->reportBillingEvent($workspaceId, 'subscription_checkout_created', [
            'stripe_checkout_session_id' => (string) ($response['id'] ?? ''),
            'plan_id' => $config['id'],
            'trial_days' => $trialDays,
            'price_cents' => $config['price_cents'],
            'currency' => $config['currency'],
        ], $billingAccountId, (string) ($response['id'] ?? ''));

        return [
            'id' => (string) ($response['id'] ?? ''),
            'url' => (string) ($response['url'] ?? ''),
        ];
    }

    public function cancelPendingSubscriptionCheckout(string $workspaceId): bool
    {
        [$subscription] = $this->billing->ensureWorkspaceBilling($workspaceId);
        $reservation = DB::transaction(function () use ($subscription): ?array {
            $record = WorkspaceSubscription::query()->where('id', $subscription->id)->lockForUpdate()->firstOrFail();
            if ($this->subscriptionIsManagedInStripe($record)) {
                return null;
            }

            $pending = (array) (((array) ($record->metadata ?? []))['pending_subscription_checkout'] ?? []);
            if ($pending === []) {
                return null;
            }

            $sessionId = trim((string) ($pending['id'] ?? ''));
            $intentToken = trim((string) ($pending['intent_token'] ?? ''));
            if ($sessionId === '' || $intentToken === '') {
                throw new RuntimeException('subscription_checkout_creating');
            }

            return ['session_id' => $sessionId, 'intent_token' => $intentToken];
        });

        if ($reservation === null) {
            return false;
        }

        $this->request(
            'POST',
            '/checkout/sessions/'.rawurlencode($reservation['session_id']).'/expire',
            [],
            'subscription-checkout-expire-'.hash('sha256', $reservation['intent_token']),
        );

        return DB::transaction(function () use ($subscription, $reservation): bool {
            $record = WorkspaceSubscription::query()->where('id', $subscription->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) ($record->metadata ?? []);
            $pending = (array) ($metadata['pending_subscription_checkout'] ?? []);
            if (($pending['intent_token'] ?? null) !== $reservation['intent_token']) {
                return false;
            }

            unset($metadata['pending_subscription_checkout']);
            $record->metadata = $metadata;
            $record->save();

            return true;
        });
    }

    public function createCustomerPortalSession(string $workspaceId, string $returnUrl): array
    {
        [$subscription] = $this->billing->ensureWorkspaceBilling($workspaceId);
        $customerId = trim((string) ($subscription->stripe_customer_id ?? ''));
        if ($customerId === '') {
            throw new RuntimeException('stripe_customer_not_found');
        }

        $response = $this->request('POST', '/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        $this->observability->reportBillingEvent($workspaceId, 'customer_portal_opened', [], (string) ($subscription->billing_account_id ?? ''), $customerId);

        return [
            'id' => (string) ($response['id'] ?? ''),
            'url' => (string) ($response['url'] ?? ''),
        ];
    }

    public function hasActivePaidSubscription(string $workspaceId): bool
    {
        [$subscription] = $this->billing->ensureWorkspaceBilling($workspaceId);

        return $this->subscriptionIsManagedInStripe($subscription);
    }

    private function workspaceEligibleForPaidTrial(string $workspaceId, string $planId): bool
    {
        [$subscription] = $this->billing->ensureWorkspaceBilling($workspaceId);

        $meta = (array) ($subscription?->metadata ?? []);
        $usedKey = 'paid_plan_trial_used_'.strtolower($planId);

        return ! ($meta[$usedKey] ?? false);
    }

    public function createTopupCheckoutSession(string $workspaceId, string $packageId, string $successUrl, string $cancelUrl): array
    {
        $package = $this->billing->getCreditPackageConfig($workspaceId, $packageId);
        [$currentSubscription] = $this->billing->ensureWorkspaceBilling($workspaceId);
        $billingAccountId = (string) ($currentSubscription->billing_account_id ?: '');
        $customerId = $this->ensureStripeCustomer($workspaceId);

        $response = $this->request('POST', '/checkout/sessions', [
            'customer' => $customerId,
            'client_reference_id' => $workspaceId,
            'mode' => 'payment',
            'allow_promotion_codes' => 'true',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'billing_type' => 'credit_topup',
                'workspace_id' => $workspaceId,
                'billing_account_id' => $billingAccountId,
                'credit_package_id' => $package['id'],
                'expected_amount_cents' => (string) $package['price_cents'],
                'currency' => $package['currency'],
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'billing_type' => 'credit_topup',
                    'workspace_id' => $workspaceId,
                    'billing_account_id' => $billingAccountId,
                    'credit_package_id' => $package['id'],
                    'plan_id_at_checkout' => $package['plan_id'] ?? 'free',
                    'topup_price_multiplier' => (string) ($package['topup_price_multiplier'] ?? 1),
                    'expected_amount_cents' => (string) $package['price_cents'],
                    'currency' => $package['currency'],
                ],
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $package['currency'],
                    'unit_amount' => $package['price_cents'],
                    'product_data' => [
                        'name' => $package['name'],
                        'description' => sprintf(
                            '%d scrape credits and %d AI credits.',
                            $package['scrape_credits'],
                            $package['ai_credits'],
                        ),
                    ],
                ],
            ]],
        ]);
        $this->observability->reportBillingEvent($workspaceId, 'topup_checkout_created', [
            'stripe_checkout_session_id' => (string) ($response['id'] ?? ''),
            'credit_package_id' => $package['id'],
            'expected_amount_cents' => $package['price_cents'],
            'currency' => $package['currency'],
            'scrape_credits' => $package['scrape_credits'],
            'ai_credits' => $package['ai_credits'],
        ], $billingAccountId, (string) ($response['id'] ?? ''));

        return [
            'id' => (string) ($response['id'] ?? ''),
            'url' => (string) ($response['url'] ?? ''),
        ];
    }

    public function handleWebhook(string $payload, ?string $signatureHeader): array
    {
        $this->verifyWebhookSignature($payload, $signatureHeader);
        $event = json_decode($payload, true);
        if (! is_array($event)) {
            throw new RuntimeException('Invalid Stripe webhook payload.');
        }

        return $this->handleWebhookEvent($event);
    }

    private function handleWebhookEvent(array $event): array
    {

        $eventId = trim((string) ($event['id'] ?? ''));
        $type = (string) ($event['type'] ?? '');
        $object = (array) Arr::get($event, 'data.object', []);

        if ($eventId !== '' && ! $this->claimWebhookEvent($eventId, $type)) {
            return [
                'received' => true,
                'type' => $type,
                'duplicate' => true,
            ];
        }

        try {
            switch ($type) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    $this->handleCheckoutSessionCompleted($object);
                    break;
                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                    $this->syncSubscriptionFromStripeObject($object);
                    break;
                case 'invoice.payment_failed':
                    $subscriptionId = trim((string) ($object['subscription'] ?? ''));
                    if ($subscriptionId !== '') {
                        $subscription = WorkspaceSubscription::query()
                            ->where('stripe_subscription_id', $subscriptionId)
                            ->first();
                        WorkspaceSubscription::query()
                            ->where('stripe_subscription_id', $subscriptionId)
                            ->update(['status' => 'past_due']);
                        if ($subscription) {
                            $this->observability->reportBillingEvent((string) $subscription->workspace_id, 'invoice_payment_failed', [
                                'stripe_subscription_id' => $subscriptionId,
                                'stripe_invoice_id' => (string) ($object['id'] ?? ''),
                                'stripe_customer_id' => (string) ($object['customer'] ?? ''),
                            ], (string) ($subscription->billing_account_id ?? ''), $subscriptionId);
                        }
                    }
                    break;
                case 'invoice.payment_succeeded':
                    $subscriptionId = trim((string) ($object['subscription'] ?? ''));
                    if ($subscriptionId !== '') {
                        $subscription = $this->retrieveSubscription($subscriptionId);
                        $this->syncSubscriptionFromStripeObject($subscription);
                    }
                    break;
            }

            if ($eventId !== '') {
                $this->markWebhookEventProcessed($eventId);
            }
        } catch (\Throwable $e) {
            if ($eventId !== '') {
                $this->markWebhookEventFailed($eventId, $e->getMessage());
            }
            $this->observability->reportWebhookFailure('stripe', $eventId, $type, $e, [
                'object_id' => (string) ($object['id'] ?? ''),
            ]);

            throw $e;
        }

        return [
            'received' => true,
            'type' => $type,
        ];
    }

    private function handleCheckoutSessionCompleted(array $session): void
    {
        $metadata = (array) ($session['metadata'] ?? []);
        $billingType = (string) ($metadata['billing_type'] ?? '');

        if ($billingType === 'credit_topup') {
            $this->fulfillTopup($session);

            return;
        }

        if ($billingType === 'subscription_checkout') {
            $subscriptionId = trim((string) ($session['subscription'] ?? ''));
            if ($subscriptionId !== '') {
                $subscription = $this->retrieveSubscription($subscriptionId);
                $this->syncSubscriptionFromStripeObject($subscription);
            }
        }
    }

    private function fulfillTopup(array $session): void
    {
        if ((string) ($session['payment_status'] ?? '') !== 'paid') {
            return;
        }

        $metadata = (array) ($session['metadata'] ?? []);
        $workspaceId = trim((string) ($metadata['workspace_id'] ?? ''));
        $billingAccountId = trim((string) ($metadata['billing_account_id'] ?? ''));
        $packageId = trim((string) ($metadata['credit_package_id'] ?? ''));
        $paymentIntentId = trim((string) ($session['payment_intent'] ?? ''));

        if ($workspaceId === '' || $packageId === '' || $paymentIntentId === '') {
            return;
        }

        if (CreditPurchase::query()->where('stripe_payment_intent_id', $paymentIntentId)->exists()) {
            return;
        }

        $package = $this->billing->getCreditPackageConfig($workspaceId, $packageId);
        $expectedAmountCents = (int) ($metadata['expected_amount_cents'] ?? $package['price_cents']);
        $actualAmountCents = (int) ($session['amount_total'] ?? 0);
        $expectedCurrency = strtolower((string) ($metadata['currency'] ?? $package['currency']));
        $actualCurrency = strtolower((string) ($session['currency'] ?? $expectedCurrency));

        if ($expectedAmountCents <= 0 || $actualAmountCents < $expectedAmountCents || $actualCurrency !== $expectedCurrency) {
            throw new RuntimeException('Stripe top-up payment did not match expected package amount.');
        }

        $this->assertStripeMetadataMatchesWorkspace($workspaceId, $billingAccountId);

        $this->billing->applyPurchasedCredits(
            $workspaceId,
            (int) $package['scrape_credits'],
            (int) $package['ai_credits'],
            [
                'credit_package_id' => $packageId,
                'stripe_payment_intent_id' => $paymentIntentId,
                'amount_paid_usd' => round(((int) ($session['amount_total'] ?? 0)) / 100, 2),
                'checkout_session_id' => (string) ($session['id'] ?? ''),
                'stripe_customer_id' => (string) ($session['customer'] ?? ''),
                'plan_id_at_purchase' => $package['plan_id'] ?? 'free',
                'base_price_usd' => $package['base_price_usd'] ?? null,
                'topup_price_multiplier' => $package['topup_price_multiplier'] ?? 1,
            ],
        );
        $this->observability->reportBillingEvent($workspaceId, 'topup_fulfilled', [
            'credit_package_id' => $packageId,
            'stripe_payment_intent_id' => $paymentIntentId,
            'stripe_checkout_session_id' => (string) ($session['id'] ?? ''),
            'amount_paid_usd' => round(((int) ($session['amount_total'] ?? 0)) / 100, 2),
            'scrape_credits' => (int) $package['scrape_credits'],
            'ai_credits' => (int) $package['ai_credits'],
        ], $billingAccountId, $paymentIntentId);
    }

    private function syncSubscriptionFromStripeObject(array $subscription): void
    {
        $metadata = (array) ($subscription['metadata'] ?? []);
        $workspaceId = trim((string) ($metadata['workspace_id'] ?? ''));
        $billingAccountId = trim((string) ($metadata['billing_account_id'] ?? ''));
        $planId = trim((string) ($metadata['plan_id'] ?? ''));
        $subscriptionId = trim((string) ($subscription['id'] ?? ''));
        $customerId = trim((string) ($subscription['customer'] ?? ''));

        if ($subscriptionId === '') {
            return;
        }

        $existing = WorkspaceSubscription::query()
            ->where('stripe_subscription_id', $subscriptionId)
            ->first();

        if (! $existing && $customerId !== '') {
            $existing = WorkspaceSubscription::query()
                ->where('stripe_customer_id', $customerId)
                ->first();
        }

        if ($workspaceId === '' && $existing) {
            $workspaceId = (string) $existing->workspace_id;
        }
        if ($billingAccountId === '' && $existing) {
            $billingAccountId = (string) ($existing->billing_account_id ?: '');
        }
        if ($planId === '' && $existing) {
            $planId = (string) ($existing->plan_id ?: '');
        }

        if ($workspaceId === '' || $planId === '') {
            throw new RuntimeException('Stripe subscription webhook could not be matched to a workspace.');
        }

        $status = $this->normalizeSubscriptionStatus((string) ($subscription['status'] ?? 'active'));
        $periodStart = $this->timestampToCarbon($subscription['current_period_start'] ?? null);
        $periodEnd = $this->timestampToCarbon($subscription['current_period_end'] ?? null);
        $trialEndsAt = $this->timestampToCarbon($subscription['trial_end'] ?? null);
        $planId = strtolower($planId);
        $this->billing->getPlanCheckoutConfig($workspaceId, $planId);

        DB::transaction(function () use ($workspaceId, $billingAccountId, $planId, $status, $customerId, $subscriptionId, $periodStart, $periodEnd, $trialEndsAt, $subscription) {
            [$record] = $this->billing->ensureWorkspaceBilling($workspaceId);
            $record = WorkspaceSubscription::query()
                ->where('id', $record->id)
                ->lockForUpdate()
                ->firstOrFail();

            $effectiveBillingAccountId = $billingAccountId !== '' ? $billingAccountId : (string) ($record->billing_account_id ?: '');
            if ($billingAccountId !== '' && $billingAccountId !== (string) ($record->billing_account_id ?: '')) {
                throw new RuntimeException('Stripe subscription billing account metadata does not match workspace.');
            }

            $currentSubscriptionId = trim((string) ($record->stripe_subscription_id ?? ''));
            if ($currentSubscriptionId !== ''
                && $currentSubscriptionId !== $subscriptionId
                && in_array(strtolower((string) $record->status), ['active', 'trialing', 'past_due', 'unpaid', 'incomplete'], true)) {
                throw new RuntimeException('stripe_subscription_conflict');
            }

            $previousPlan = (string) ($record->plan_id ?: 'free');
            $record->plan_id = $planId;
            $record->status = $status;
            $record->stripe_customer_id = $customerId !== '' ? $customerId : $record->stripe_customer_id;
            $record->stripe_subscription_id = $subscriptionId;
            $record->current_period_start = $periodStart;
            $record->current_period_end = $periodEnd;
            $record->trial_ends_at = $trialEndsAt;
            $metadata = (array) ($record->metadata ?? []);
            $metadata['stripe_synced_at'] = now()->toIso8601String();
            $metadata['cancel_at_period_end'] = (bool) ($subscription['cancel_at_period_end'] ?? false);
            $metadata['cancel_at'] = $this->timestampToCarbon($subscription['cancel_at'] ?? null)?->toIso8601String();
            $metadata['canceled_at'] = $this->timestampToCarbon($subscription['canceled_at'] ?? null)?->toIso8601String();
            unset($metadata['pending_subscription_checkout']);

            if ($trialEndsAt !== null && in_array($planId, ['pro', 'enterprise'], true)) {
                $metadata['paid_plan_trial_used_'.$planId] = true;
            }

            $record->metadata = $metadata;
            $record->billing_account_id = $effectiveBillingAccountId ?: $record->billing_account_id;
            $record->save();

            if ($effectiveBillingAccountId !== '') {
                DB::table('billing_accounts')->where('id', $effectiveBillingAccountId)->update(['plan_id' => $planId, 'updated_at' => now()]);
                DB::table('workspaces')->where('billing_account_id', $effectiveBillingAccountId)->update(['plan_id' => $planId, 'updated_at' => now()]);
            } else {
                DB::table('workspaces')->where('id', $workspaceId)->update(['plan_id' => $planId]);
            }

            $periodKey = $periodStart?->toIso8601String();
            if ($periodKey && ($previousPlan !== $planId || (($metadata['last_refill_period_key'] ?? null) !== $periodKey && in_array($status, ['active', 'trialing'], true)))) {
                $this->billing->grantPlanCycleCredits($workspaceId, $planId, $periodStart, $previousPlan !== $planId);
            }
        });

        if ($status === 'canceled') {
            $this->observability->reportBillingEvent($workspaceId, 'subscription_canceled', [
                'plan_id' => $planId,
                'stripe_subscription_id' => $subscriptionId,
                'stripe_customer_id' => $customerId,
            ], $billingAccountId, $subscriptionId);
        }

        $this->observability->reportBillingEvent($workspaceId, 'subscription_synced', [
            'plan_id' => $planId,
            'status' => $status,
            'stripe_subscription_id' => $subscriptionId,
            'stripe_customer_id' => $customerId,
            'current_period_start' => $periodStart?->toIso8601String(),
            'current_period_end' => $periodEnd?->toIso8601String(),
        ], $billingAccountId, $subscriptionId);
    }

    private function ensureStripeCustomer(string $workspaceId): string
    {
        [$subscription] = $this->billing->ensureWorkspaceBilling($workspaceId);
        $existing = trim((string) ($subscription?->stripe_customer_id ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $workspace = DB::table('workspaces')->where('id', $workspaceId)->first();
        if (! $workspace) {
            throw new RuntimeException('Workspace not found for Stripe billing.');
        }

        $contact = $this->resolveWorkspaceContact($workspaceId);
        $response = $this->request('POST', '/customers', [
            'email' => $contact['email'] ?? null,
            'name' => (string) ($workspace->name ?? 'Social CORE Workspace'),
            'metadata' => [
                'workspace_id' => $workspaceId,
                'billing_account_id' => (string) ($subscription?->billing_account_id ?: ''),
            ],
        ], 'stripe-customer-'.hash('sha256', (string) ($subscription?->billing_account_id ?: $workspaceId)));

        $customerId = trim((string) ($response['id'] ?? ''));
        if ($customerId === '') {
            throw new RuntimeException('Stripe did not return a customer ID.');
        }

        WorkspaceSubscription::query()->updateOrCreate(
            ['id' => $subscription?->id ?: (string) \Illuminate\Support\Str::uuid()],
            [
                'workspace_id' => $subscription?->workspace_id ?: $workspaceId,
                'billing_account_id' => $subscription?->billing_account_id,
                'plan_id' => $subscription?->plan_id ?: 'free',
                'status' => $subscription?->status ?: 'trialing',
                'stripe_customer_id' => $customerId,
                'current_period_start' => $subscription?->current_period_start,
                'current_period_end' => $subscription?->current_period_end,
                'trial_ends_at' => $subscription?->trial_ends_at,
                'metadata' => $subscription?->metadata ?: ['bootstrap' => true],
            ]
        );

        return $customerId;
    }

    private function resolveWorkspaceContact(string $workspaceId): array
    {
        $workspace = DB::table('workspaces')->where('id', $workspaceId)->first();
        if (! $workspace) {
            return [];
        }

        if (! empty($workspace->owner_id)) {
            $owner = DB::table('users')
                ->where('supabase_user_id', $workspace->owner_id)
                ->first();

            if ($owner) {
                return [
                    'email' => $owner->email,
                    'name' => $owner->name,
                ];
            }
        }

        $member = DB::table('workspace_members')
            ->join('users', function ($join) {
                $join->on(
                    'users.supabase_user_id',
                    '=',
                    DB::raw('CAST(workspace_members.user_id AS TEXT)')
                );
            })
            ->where('workspace_members.workspace_id', $workspaceId)
            ->orderByRaw("CASE workspace_members.role WHEN 'owner' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END")
            ->select('users.email', 'users.name')
            ->first();

        return $member
            ? ['email' => $member->email, 'name' => $member->name]
            : [];
    }

    private function assertStripeMetadataMatchesWorkspace(string $workspaceId, string $billingAccountId): void
    {
        if ($billingAccountId === '') {
            return;
        }

        [$subscription] = $this->billing->ensureWorkspaceBilling($workspaceId);
        if ($billingAccountId !== (string) ($subscription->billing_account_id ?: '')) {
            throw new RuntimeException('Stripe checkout billing account metadata does not match workspace.');
        }
    }

    private function verifyWebhookSignature(string $payload, ?string $signatureHeader): void
    {
        $secret = trim((string) config('services.stripe.webhook_secret'));
        if ($secret === '') {
            throw new RuntimeException('Stripe webhook secret is not configured.');
        }

        $parts = [];
        foreach (explode(',', (string) $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key][] = $value;
            }
        }

        $timestamp = Arr::first($parts['t'] ?? []);
        $signatures = $parts['v1'] ?? [];
        if (! $timestamp || $signatures === []) {
            throw new RuntimeException('Invalid Stripe signature header.');
        }

        $tolerance = (int) config('outreach.billing.stripe_webhook_tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new RuntimeException('Stripe webhook timestamp outside tolerance.');
        }

        $signedPayload = $timestamp.'.'.$payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new RuntimeException('Stripe webhook signature verification failed.');
    }

    private function claimWebhookEvent(string $eventId, string $type): bool
    {
        $inserted = DB::table('stripe_webhook_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'stripe_event_id' => $eventId,
            'type' => $type,
            'status' => 'processing',
            'attempt_count' => 1,
            'last_attempt_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            return true;
        }

        return DB::transaction(function () use ($eventId, $type) {
            $row = DB::table('stripe_webhook_events')
                ->where('stripe_event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($row && $row->status === 'processed') {
                return false;
            }

            if ($row) {
                $leaseMinutes = max(1, (int) config('outreach.billing.stripe_webhook_processing_lease_minutes', 10));
                $lastAttemptAt = $row->last_attempt_at ?: $row->updated_at;
                if ($row->status === 'processing'
                    && $lastAttemptAt
                    && CarbonImmutable::parse($lastAttemptAt)->gt(now()->subMinutes($leaseMinutes))) {
                    return false;
                }

                $attemptCount = (int) ($row->attempt_count ?? 0) + 1;
                $maxAttempts = max(1, (int) config('outreach.billing.stripe_webhook_max_attempts', 8));
                if ($attemptCount > $maxAttempts) {
                    DB::table('stripe_webhook_events')
                        ->where('stripe_event_id', $eventId)
                        ->update([
                            'status' => 'dead_lettered',
                            'last_error' => 'Maximum webhook processing attempts exceeded.',
                            'updated_at' => now(),
                        ]);
                    $this->observability->reportWebhookFailure('stripe', $eventId, $type, 'Maximum webhook processing attempts exceeded.');

                    return false;
                }

                DB::table('stripe_webhook_events')
                    ->where('stripe_event_id', $eventId)
                    ->update([
                        'type' => $type,
                        'status' => 'processing',
                        'attempt_count' => $attemptCount,
                        'last_attempt_at' => now(),
                        'last_error' => null,
                        'updated_at' => now(),
                    ]);

                return true;
            }

            return false;
        });
    }

    private function markWebhookEventProcessed(string $eventId): void
    {
        DB::table('stripe_webhook_events')
            ->where('stripe_event_id', $eventId)
            ->update([
                'status' => 'processed',
                'processed_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    private function markWebhookEventFailed(string $eventId, string $message): void
    {
        DB::table('stripe_webhook_events')
            ->where('stripe_event_id', $eventId)
            ->update([
                'status' => 'failed',
                'last_error' => Str::limit($message, 2000, ''),
                'updated_at' => now(),
            ]);
    }

    private function retrieveSubscription(string $subscriptionId): array
    {
        return $this->request('GET', '/subscriptions/'.urlencode($subscriptionId));
    }

    public function reconcileStripeSubscriptions(?int $limit = null, ?string $workspaceId = null): array
    {
        if (trim((string) config('services.stripe.secret_key')) === '') {
            return ['checked' => 0, 'errors' => 0];
        }

        $query = WorkspaceSubscription::query()
            ->whereNotNull('stripe_subscription_id')
            ->where('stripe_subscription_id', '!=', '')
            ->orderBy('updated_at');
        if ($workspaceId !== null && trim($workspaceId) !== '') {
            $query->where('workspace_id', $workspaceId);
        }
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $checked = 0;
        $errors = 0;
        foreach ($query->get() as $record) {
            $checked++;
            try {
                $this->syncSubscriptionFromStripeObject($this->retrieveSubscription((string) $record->stripe_subscription_id));
            } catch (\Throwable $exception) {
                $errors++;
                $this->observability->reportWebhookFailure('stripe_reconciliation', (string) $record->stripe_subscription_id, 'subscription.reconcile', $exception);
            }
        }

        return ['checked' => $checked, 'errors' => $errors];
    }

    public function reconcileRecoverableWebhookEvents(?int $limit = null): array
    {
        if (trim((string) config('services.stripe.secret_key')) === '') {
            return ['checked' => 0, 'recovered' => 0, 'errors' => 0];
        }

        $leaseMinutes = max(1, (int) config('outreach.billing.stripe_webhook_processing_lease_minutes', 10));
        $query = DB::table('stripe_webhook_events')
            ->where(function ($candidate) use ($leaseMinutes) {
                $candidate->where('status', 'failed')
                    ->orWhere(function ($processing) use ($leaseMinutes) {
                        $processing->where('status', 'processing')
                            ->where(function ($stale) use ($leaseMinutes) {
                                $stale->where('last_attempt_at', '<', now()->subMinutes($leaseMinutes))
                                    ->orWhere(function ($fallback) use ($leaseMinutes) {
                                        $fallback->whereNull('last_attempt_at')
                                            ->where('updated_at', '<', now()->subMinutes($leaseMinutes));
                                    });
                            });
                    });
            })
            ->orderBy('updated_at');
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $checked = 0;
        $recovered = 0;
        $errors = 0;
        foreach ($query->get() as $record) {
            $checked++;
            try {
                $result = $this->handleWebhookEvent($this->request('GET', '/events/'.urlencode((string) $record->stripe_event_id)));
                if (! ($result['duplicate'] ?? false)) {
                    $recovered++;
                }
            } catch (\Throwable $exception) {
                $errors++;
                $this->observability->reportWebhookFailure('stripe_reconciliation', (string) $record->stripe_event_id, (string) $record->type, $exception);
            }
        }

        return ['checked' => $checked, 'recovered' => $recovered, 'errors' => $errors];
    }

    private function subscriptionIsManagedInStripe(WorkspaceSubscription $subscription): bool
    {
        return trim((string) ($subscription->stripe_subscription_id ?? '')) !== ''
            && in_array(strtolower((string) $subscription->status), ['active', 'trialing', 'past_due', 'unpaid', 'incomplete'], true);
    }

    private function normalizeSubscriptionStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'active', 'trialing', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired' => $status,
            default => 'active',
        };
    }

    private function timestampToCarbon(mixed $timestamp): ?CarbonImmutable
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }
        if (! is_numeric($timestamp)) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $timestamp);
    }

    private function request(string $method, string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        $secret = trim((string) config('services.stripe.secret_key'));
        if ($secret === '') {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        $client = Http::acceptJson()
            ->timeout(45)
            ->withToken($secret)
            ->baseUrl('https://api.stripe.com/v1');
        if ($idempotencyKey) {
            $client = $client->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        if ($method === 'GET') {
            $response = $client->get($path, $payload);
        } else {
            $response = $client->asForm()->post($path, $this->flatten($payload));
        }

        if (! $response->successful()) {
            $message = (string) ($response->json('error.message') ?: $response->body());
            throw new RuntimeException('Stripe API request failed: '.$message);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Stripe API returned an invalid response.');
        }

        return $json;
    }

    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $composite = $prefix === '' ? (string) $key : $prefix.'['.$key.']';
            if (is_array($value)) {
                $flat += $this->flatten($value, $composite);
            } elseif (is_bool($value)) {
                $flat[$composite] = $value ? 'true' : 'false';
            } else {
                $flat[$composite] = (string) $value;
            }
        }

        return $flat;
    }
}
