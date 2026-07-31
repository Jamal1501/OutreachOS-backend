<?php

namespace App\Console\Commands;

use App\Services\WorkspaceBillingService;
use Illuminate\Console\Command;

class ReconcileWorkspaceBillingCommand extends Command
{
    protected $signature = 'billing:reconcile-workspaces {--workspace=} {--limit=}';

    protected $description = 'Reconcile workspace billing periods, refills, and trial expiry states.';

    public function handle(WorkspaceBillingService $billing): int
    {
        $workspaceId = trim((string) $this->option('workspace'));
        if ($workspaceId !== '') {
            $billing->reconcileWorkspace($workspaceId);
            $this->info('Workspace billing reconciled: '.$workspaceId);

            return self::SUCCESS;
        }

        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;
        $result = $billing->reconcileAllWorkspaces($limit);
        $this->info(sprintf('Billing reconciled. Checked: %d, errors: %d', $result['checked'], $result['errors']));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
