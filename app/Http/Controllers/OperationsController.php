<?php

namespace App\Http\Controllers;

use App\Services\ProviderSpendGuardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OperationsController extends Controller
{
    public function __construct(private ProviderSpendGuardService $providerSpend) {}

    public function providerSpend(): mixed
    {
        return response()->json([
            'message' => 'Provider spend controls fetched',
            'data' => [
                'checkedAt' => now()->toIso8601String(),
                'providers' => [
                    $this->providerSpend->overview('apify'),
                    $this->providerSpend->overview('openai'),
                ],
            ],
        ]);
    }

    public function updateProviderSpendControl(Request $request): mixed
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(['apify', 'openai'])],
            'scope' => ['required', 'string', Rule::in(['global', 'workspace'])],
            'workspaceId' => ['nullable', 'uuid', Rule::requiredIf(fn () => $request->input('scope') === 'workspace')],
            'dailyLimitUsd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'overrideLimitUsd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'overrideUntil' => ['nullable', 'date', 'after:now'],
            'overrideReason' => ['nullable', 'string', 'max:300'],
            'bypassTemporarily' => ['nullable', 'boolean'],
            'clearOverride' => ['nullable', 'boolean'],
        ]);

        $workspaceId = trim((string) ($validated['workspaceId'] ?? '')) ?: null;
        if ($workspaceId && ! DB::table('workspaces')->where('id', $workspaceId)->exists()) {
            return response()->json(['error' => 'Workspace not found.'], 404);
        }

        $clearOverride = (bool) ($validated['clearOverride'] ?? false);
        $updateDailyLimit = array_key_exists('dailyLimitUsd', $validated);
        $updateOverride = $clearOverride
            || (bool) ($validated['bypassTemporarily'] ?? false)
            || array_key_exists('overrideLimitUsd', $validated)
            || array_key_exists('overrideUntil', $validated);
        $overrideUntil = $clearOverride || empty($validated['overrideUntil'])
            ? null
            : CarbonImmutable::parse((string) $validated['overrideUntil']);
        $overrideLimit = $clearOverride || (bool) ($validated['bypassTemporarily'] ?? false)
            ? null
            : (isset($validated['overrideLimitUsd']) ? (float) $validated['overrideLimitUsd'] : null);

        $control = $this->providerSpend->updateControl(
            provider: (string) $validated['provider'],
            scope: (string) $validated['scope'],
            workspaceId: $workspaceId,
            dailyLimitUsd: isset($validated['dailyLimitUsd']) ? (float) $validated['dailyLimitUsd'] : null,
            overrideLimitUsd: $overrideLimit,
            overrideUntil: $overrideUntil,
            overrideReason: $clearOverride ? null : ($validated['overrideReason'] ?? null),
            updatedByUserId: (string) $request->attributes->get('supabase_user_id'),
            updateDailyLimit: $updateDailyLimit,
            updateOverride: $updateOverride,
        );

        return response()->json([
            'message' => 'Provider spend control updated',
            'data' => $control,
        ]);
    }
}
