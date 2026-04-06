<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceBillingService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private WorkspaceBillingService $billing)
    {
    }

    public function summary(Request $request)
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');

        return response()->json([
            'message' => 'Billing summary fetched',
            'data' => $this->billing->summary($workspaceId),
        ]);
    }
}
