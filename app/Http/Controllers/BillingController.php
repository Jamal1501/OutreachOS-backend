<?php

namespace App\Http\Controllers;

use App\Services\StripeBillingService;
use App\Services\WorkspaceBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    public function __construct(
        private WorkspaceBillingService $billing,
        private StripeBillingService $stripeBilling,
    ) {
    }

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
        $result = $this->stripeBilling->handleWebhook(
            $request->getContent(),
            $request->header('Stripe-Signature'),
        );

        return response()->json($result);
    }

    private function validatedCheckoutReturnUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || !in_array($scheme, ['https', 'http'], true)) {
            throw ValidationException::withMessages([
                'successUrl' => 'Invalid checkout return URL.',
            ]);
        }

        if ($scheme !== 'https' && !in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw ValidationException::withMessages([
                'successUrl' => 'Checkout return URL must use HTTPS.',
            ]);
        }

        if (!$this->checkoutReturnHostAllowed($host)) {
            throw ValidationException::withMessages([
                'successUrl' => 'Checkout return URL host is not allowed.',
            ]);
        }

        return $url;
    }

    private function checkoutReturnHostAllowed(string $host): bool
    {
        $configured = array_filter(array_map('trim', explode(',', (string) env('BILLING_ALLOWED_REDIRECT_HOSTS', ''))));
        $allowedHosts = array_values(array_unique(array_merge([
            'socialcore.app',
            'www.socialcore.app',
            'croos.lovable.app',
            'localhost',
            '127.0.0.1',
        ], array_map('strtolower', $configured))));

        if (in_array($host, $allowedHosts, true)) {
            return true;
        }

        // Temporary preview-host allowance. Replace with explicit hosts in BILLING_ALLOWED_REDIRECT_HOSTS before scale.
        return Str::endsWith($host, '.vercel.app') || Str::endsWith($host, '.lovable.app');
    }
}
