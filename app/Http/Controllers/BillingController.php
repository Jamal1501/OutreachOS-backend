<?php

namespace App\Http\Controllers;

use App\Services\StripeBillingService;
use App\Services\WorkspaceBillingService;
use Illuminate\Http\Request;

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

        $session = $this->stripeBilling->createSubscriptionCheckoutSession(
            $workspaceId,
            (string) $validated['planId'],
            (string) $validated['successUrl'],
            (string) $validated['cancelUrl'],
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

        $session = $this->stripeBilling->createTopupCheckoutSession(
            $workspaceId,
            (string) $validated['packageId'],
            (string) $validated['successUrl'],
            (string) $validated['cancelUrl'],
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
}
