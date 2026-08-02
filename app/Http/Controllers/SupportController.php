<?php

namespace App\Http\Controllers;

use App\Jobs\SendSupportRequestMail;
use App\Models\SupportRequest;
use App\Services\ObservabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SupportController extends Controller
{
    public function __construct(private ObservabilityService $observability) {}

    public function banner()
    {
        $databaseBanner = Schema::hasTable('platform_incident_banners')
            ? DB::table('platform_incident_banners')
                ->orderByDesc('updated_at')
                ->first()
            : null;
        if ($databaseBanner) {
            $isActive = (bool) $databaseBanner->enabled
                && ($databaseBanner->starts_at === null || now()->greaterThanOrEqualTo($databaseBanner->starts_at))
                && ($databaseBanner->expires_at === null || now()->lessThan($databaseBanner->expires_at));

            return response()->json([
                'data' => [
                    'enabled' => $isActive,
                    'severity' => in_array($databaseBanner->severity, ['info', 'warning', 'critical'], true) ? $databaseBanner->severity : 'warning',
                    'message' => $isActive ? (string) $databaseBanner->message : null,
                    'startsAt' => $databaseBanner->starts_at,
                    'expiresAt' => $databaseBanner->expires_at,
                ],
            ]);
        }

        $message = trim((string) config('support.incident_banner.message'));
        $enabled = (bool) config('support.incident_banner.enabled') && $message !== '';
        $severity = strtolower((string) config('support.incident_banner.severity', 'warning'));

        return response()->json([
            'data' => [
                'enabled' => $enabled,
                'severity' => in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'warning',
                'message' => $enabled ? $message : null,
                'startsAt' => null,
                'expiresAt' => null,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'in:product_question,technical_problem,billing,data_privacy,other'],
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            'page' => ['nullable', 'string', 'max:500'],
        ]);
        $reference = 'SUP-'.Str::upper(Str::random(10));
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $userId = (string) $request->attributes->get('supabase_user_id');
        $email = (string) DB::table('users')->where('supabase_user_id', $userId)->value('email');
        $supportRequest = SupportRequest::query()->create([
            'reference' => $reference,
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'email' => $email ?: null,
            'category' => $validated['category'],
            'subject' => trim((string) $validated['subject']),
            'message' => trim((string) $validated['message']),
            'page' => isset($validated['page']) ? trim((string) $validated['page']) : null,
            'status' => 'pending',
        ]);

        try {
            SendSupportRequestMail::dispatch((string) $supportRequest->id);
        } catch (Throwable $exception) {
            $supportRequest->forceFill([
                'status' => 'failed',
                'last_delivery_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();
            Log::warning('Support request was saved but mail delivery could not be queued', [
                'reference' => $reference,
                'workspace_id' => $workspaceId,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->observability->audit(
            $workspaceId,
            'support_request_submitted',
            'support_request',
            $reference,
            ['reference' => $reference, 'category' => $validated['category']],
            $userId,
        );

        return response()->json([
            'message' => 'Support request received',
            'data' => ['reference' => $reference],
        ], 201);
    }
}
