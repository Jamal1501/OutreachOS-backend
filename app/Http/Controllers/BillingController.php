<?php

namespace App\Http\Controllers;

use App\Services\ObservabilityService;
use App\Services\StripeBillingService;
use App\Services\WorkspaceBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BillingController extends Controller
{
    public function __construct(
        private WorkspaceBillingService $billing,
        private StripeBillingService $stripeBilling,
        private ObservabilityService $observability,
    ) {}

    public function summary(Request $request)
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');

        return response()->json([
            'message' => 'Billing summary fetched',
            'data' => $this->billing->summary($workspaceId),
        ]);
    }

    public function catalog(Request $request)
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');

        return response()->json([
            'message' => 'Billing catalog fetched',
            'data' => $this->billing->catalog($workspaceId),
        ]);
    }

    public function qaChecklist(Request $request)
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $summary = $this->billing->summary($workspaceId);
        $billingAccountId = (string) data_get($summary, 'billingAccount.id', '');
        $workspaceIds = $billingAccountId !== '' && Schema::hasTable('workspaces') && Schema::hasColumn('workspaces', 'billing_account_id')
            ? DB::table('workspaces')->where('billing_account_id', $billingAccountId)->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [$workspaceId];

        $subscription = Schema::hasTable('workspace_subscriptions')
            ? DB::table('workspace_subscriptions')
                ->when($billingAccountId !== '' && Schema::hasColumn('workspace_subscriptions', 'billing_account_id'), fn ($query) => $query->where('billing_account_id', $billingAccountId), fn ($query) => $query->where('workspace_id', $workspaceId))
                ->orderByDesc('updated_at')
                ->first()
            : null;

        $wallet = Schema::hasTable('workspace_credit_wallets')
            ? DB::table('workspace_credit_wallets')
                ->when($billingAccountId !== '' && Schema::hasColumn('workspace_credit_wallets', 'billing_account_id'), fn ($query) => $query->where('billing_account_id', $billingAccountId), fn ($query) => $query->where('workspace_id', $workspaceId))
                ->first()
            : null;

        $recentUsage = Schema::hasTable('workspace_usage_events')
            ? DB::table('workspace_usage_events')
                ->when($billingAccountId !== '' && Schema::hasColumn('workspace_usage_events', 'billing_account_id'), fn ($query) => $query->where('billing_account_id', $billingAccountId), fn ($query) => $query->whereIn('workspace_id', $workspaceIds))
                ->select(['id', 'workspace_id', 'type', 'credit_bucket', 'units', 'credit_cost', 'provider', 'source', 'status', 'reference_id', 'metadata', 'created_at', 'consumed_at', 'refunded_at'])
                ->orderByDesc('created_at')
                ->limit(25)
                ->get()
            : collect();
        $workspaceNames = Schema::hasTable('workspaces')
            ? DB::table('workspaces')->whereIn('id', $workspaceIds)->pluck('name', 'id')
            : collect();
        $recentUsage->transform(function ($event) use ($workspaceNames) {
            $metadata = is_string($event->metadata ?? null)
                ? (json_decode($event->metadata, true) ?: [])
                : (array) ($event->metadata ?? []);
            $event->metadata = $metadata;
            $event->workspace_name = (string) ($workspaceNames[$event->workspace_id] ?? 'Workspace');
            $event->description = $this->usageEventDescription($event, $metadata);

            return $event;
        });

        $recentPurchases = Schema::hasTable('credit_purchases')
            ? DB::table('credit_purchases')
                ->when($billingAccountId !== '' && Schema::hasColumn('credit_purchases', 'billing_account_id'), fn ($query) => $query->where('billing_account_id', $billingAccountId), fn ($query) => $query->whereIn('workspace_id', $workspaceIds))
                ->select(['id', 'workspace_id', 'credit_package_id', 'stripe_payment_intent_id', 'scrape_credits_added', 'ai_credits_added', 'amount_paid_usd', 'created_at'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
            : collect();

        $recentAuditEvents = Schema::hasTable('workspace_audit_events')
            ? DB::table('workspace_audit_events')
                ->whereIn('workspace_id', $workspaceIds)
                ->where(function ($query) {
                    $query->where('event_type', 'like', 'billing_%')
                        ->orWhere('event_type', 'like', '%checkout%')
                        ->orWhere('event_type', 'like', '%topup%')
                        ->orWhere('event_type', 'like', '%subscription%')
                        ->orWhere('event_type', 'like', '%credits%');
                })
                ->select(['id', 'workspace_id', 'event_type', 'subject_type', 'subject_id', 'metadata', 'created_at'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
            : collect();

        $stripeWebhookEvents = Schema::hasTable('stripe_webhook_events')
            ? DB::table('stripe_webhook_events')
                ->select(['stripe_event_id', 'type', 'status', 'processed_at', 'last_error', 'created_at', 'updated_at'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
            : collect();

        $failedWebhookCount = $stripeWebhookEvents->where('status', 'failed')->count();
        $reservedUsageCount = $recentUsage
            ->where('status', 'reserved')
            ->filter(fn ($event) => $event->created_at && now()->parse($event->created_at)->lt(now()->subHour()))
            ->count();
        $subscriptionStatus = (string) ($subscription->status ?? data_get($summary, 'subscription.status', ''));
        $blockedStatuses = ['past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired'];

        return response()->json([
            'message' => 'Billing QA checklist fetched',
            'data' => [
                'workspaceId' => $workspaceId,
                'billingAccountId' => $billingAccountId,
                'checkedAt' => now()->toIso8601String(),
                'checks' => [
                    ['key' => 'subscription_present', 'ok' => $subscription !== null, 'detail' => $subscription ? 'Subscription row exists.' : 'No subscription row found.'],
                    ['key' => 'wallet_present', 'ok' => $wallet !== null, 'detail' => $wallet ? 'Credit wallet row exists.' : 'No credit wallet row found.'],
                    ['key' => 'subscription_can_spend', 'ok' => ! in_array($subscriptionStatus, $blockedStatuses, true), 'detail' => $subscriptionStatus ?: 'unknown'],
                    ['key' => 'stripe_customer_linked', 'ok' => trim((string) ($subscription->stripe_customer_id ?? '')) !== '' || data_get($summary, 'currentPlanId') === 'free', 'detail' => trim((string) ($subscription->stripe_customer_id ?? '')) !== '' ? 'Stripe customer linked.' : 'Free plan may not have a Stripe customer yet.'],
                    ['key' => 'stripe_subscription_linked', 'ok' => trim((string) ($subscription->stripe_subscription_id ?? '')) !== '' || data_get($summary, 'currentPlanId') === 'free', 'detail' => trim((string) ($subscription->stripe_subscription_id ?? '')) !== '' ? 'Stripe subscription linked.' : 'Free plan may not have a Stripe subscription.'],
                    ['key' => 'wallet_non_negative', 'ok' => $wallet !== null && min((int) ($wallet->scrape_credits_balance ?? 0), (int) ($wallet->ai_credits_balance ?? 0), (int) ($wallet->bonus_scrape_credits ?? 0), (int) ($wallet->bonus_ai_credits ?? 0)) >= 0, 'detail' => 'Wallet balances are unsigned in schema, but this verifies the current snapshot.'],
                    ['key' => 'no_failed_stripe_webhooks_recent', 'ok' => $failedWebhookCount === 0, 'detail' => $failedWebhookCount.' failed Stripe webhook events in the latest 20.'],
                    ['key' => 'no_stale_reserved_usage_recent', 'ok' => $reservedUsageCount === 0, 'detail' => $reservedUsageCount.' credit reservations older than one hour in the latest 25 events.'],
                    ['key' => 'billing_audit_visible', 'ok' => $recentAuditEvents->isNotEmpty(), 'detail' => $recentAuditEvents->count().' recent billing audit events found.'],
                ],
                'summary' => $summary,
                'recentUsageEvents' => $recentUsage,
                'recentCreditPurchases' => $recentPurchases,
                'recentBillingAuditEvents' => $recentAuditEvents,
                'recentStripeWebhookEvents' => $stripeWebhookEvents,
            ],
        ]);
    }

    public function checkoutSubscription(Request $request)
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $validated = $request->validate([
            'planId' => ['required', 'string'],
            'successUrl' => ['required', 'url'],
            'cancelUrl' => ['required', 'url'],
        ]);

        $successUrl = $this->validatedCheckoutReturnUrl((string) $validated['successUrl']);
        $cancelUrl = $this->validatedCheckoutReturnUrl((string) $validated['cancelUrl']);

        $session = $this->stripeBilling->createSubscriptionCheckoutSession(
            $workspaceId,
            (string) $validated['planId'],
            $successUrl,
            $cancelUrl,
        );

        return response()->json([
            'message' => 'Subscription checkout session created',
            'data' => $session,
        ]);
    }

    public function checkoutTopup(Request $request)
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $validated = $request->validate([
            'packageId' => ['required', 'string'],
            'successUrl' => ['required', 'url'],
            'cancelUrl' => ['required', 'url'],
        ]);

        $successUrl = $this->validatedCheckoutReturnUrl((string) $validated['successUrl']);
        $cancelUrl = $this->validatedCheckoutReturnUrl((string) $validated['cancelUrl']);

        $session = $this->stripeBilling->createTopupCheckoutSession(
            $workspaceId,
            (string) $validated['packageId'],
            $successUrl,
            $cancelUrl,
        );

        return response()->json([
            'message' => 'Top-up checkout session created',
            'data' => $session,
        ]);
    }

    public function stripeWebhook(Request $request)
    {
        try {
            $result = $this->stripeBilling->handleWebhook(
                $request->getContent(),
                $request->header('Stripe-Signature'),
            );
        } catch (Throwable $exception) {
            $event = json_decode($request->getContent(), true);
            $eventId = is_array($event) ? trim((string) ($event['id'] ?? '')) : '';
            $alreadyTracked = $eventId !== ''
                && Schema::hasTable('stripe_webhook_events')
                && DB::table('stripe_webhook_events')->where('stripe_event_id', $eventId)->exists();

            if (! $alreadyTracked) {
                $this->observability->reportWebhookFailure(
                    'stripe',
                    $eventId,
                    is_array($event) ? (string) ($event['type'] ?? '') : '',
                    $exception,
                );
            }

            throw $exception;
        }

        return response()->json($result);
    }

    private function validatedCheckoutReturnUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            throw ValidationException::withMessages([
                'successUrl' => 'Invalid checkout return URL.',
            ]);
        }

        if ($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw ValidationException::withMessages([
                'successUrl' => 'Checkout return URL must use HTTPS.',
            ]);
        }

        if (! $this->checkoutReturnHostAllowed($host)) {
            throw ValidationException::withMessages([
                'successUrl' => 'Checkout return URL host is not allowed.',
            ]);
        }

        return $url;
    }

    private function usageEventDescription(object $event, array $metadata): string
    {
        $source = strtolower(trim((string) (($event->source ?? '').' '.($event->type ?? ''))));
        $activity = str_contains($source, 'enrich')
            ? 'Creator enrichment'
            : (str_contains($source, 'discover') || str_contains($source, 'pipeline')
                ? 'Creator discovery'
                : (str_contains($source, 'ai') || str_contains($source, 'draft') || str_contains($source, 'message')
                    ? 'AI drafting'
                    : Str::headline((string) ($event->type ?? 'Credit usage'))));
        $creditCost = max(0, (int) ($event->credit_cost ?? 0));
        $original = max($creditCost, (int) ($metadata['original_credit_cost'] ?? $creditCost));
        $returned = (string) ($event->status ?? '') === 'refunded'
            ? $original
            : max(0, (int) ($metadata['refunded_credit_cost'] ?? 0));
        $bucket = (string) ($event->credit_bucket ?? '') === 'ai' ? 'AI' : 'workflow';

        return match ((string) ($event->status ?? '')) {
            'reserved' => "{$activity}: {$original} {$bucket} credits reserved",
            'refunded' => "{$activity}: {$returned} {$bucket} credits returned",
            'consumed' => $returned > 0
                ? "{$activity}: {$creditCost} {$bucket} credits used, {$returned} returned"
                : "{$activity}: {$creditCost} {$bucket} credits used",
            default => "{$activity}: {$creditCost} {$bucket} credits",
        };
    }

    private function checkoutReturnHostAllowed(string $host): bool
    {
        $configured = array_filter(array_map('trim', explode(',', (string) env('BILLING_ALLOWED_REDIRECT_HOSTS', ''))));
        $allowedHosts = array_values(array_unique(array_merge([
            'socialcore.app',
            'www.socialcore.app',
            'localhost',
            '127.0.0.1',
        ], array_map('strtolower', $configured))));

        if (in_array($host, $allowedHosts, true)) {
            return true;
        }

        // Temporary preview-host allowance. Replace with explicit hosts in BILLING_ALLOWED_REDIRECT_HOSTS before scale.
        return Str::endsWith($host, '.vercel.app');
    }
}
