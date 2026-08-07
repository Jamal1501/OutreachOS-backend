<?php

namespace App\Console\Commands;

use App\Services\StripeBillingService;
use App\Services\WorkspaceBillingService;
use Illuminate\Console\Command;

class ReconcileWorkspaceBillingCommand extends Command
{
    protected $signature = 'billing:reconcile-workspaces {--workspace=} {--limit=}';

    protected $description = 'Reconcile local billing periods and active Stripe subscription state.';

    public function handle(WorkspaceBillingService $billing, StripeBillingService $stripeBilling): int
    {
        $workspaceId = trim((string) $this->option('workspace'));
        if ($workspaceId !== '') {
            $billing->reconcileWorkspace($workspaceId);
            $stripeResult = $stripeBilling->reconcileStripeSubscriptions(1, $workspaceId);
            $webhookResult = $stripeBilling->reconcileRecoverableWebhookEvents(25);
            $this->info('Workspace billing reconciled: '.$workspaceId);

            return ($stripeResult['errors'] + $webhookResult['errors']) > 0 ? self::FAILURE : self::SUCCESS;
        }

        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;
        $result = $billing->reconcileAllWorkspaces($limit);
        $stripeResult = $stripeBilling->reconcileStripeSubscriptions($limit);
        $webhookResult = $stripeBilling->reconcileRecoverableWebhookEvents($limit);
        $errors = $result['errors'] + $stripeResult['errors'] + $webhookResult['errors'];
        $this->info(sprintf(
            'Billing reconciled. Local checked: %d, Stripe checked: %d, webhooks recovered: %d, errors: %d',
            $result['checked'],
            $stripeResult['checked'],
            $webhookResult['recovered'],
            $errors,
        ));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
