<?php

namespace App\Http\Controllers;

use App\Mail\SupportRequestMail;
use App\Services\ObservabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SupportController extends Controller
{
    public function __construct(private ObservabilityService $observability) {}

    public function banner()
    {
        $message = trim((string) config('support.incident_banner.message'));
        $enabled = (bool) config('support.incident_banner.enabled') && $message !== '';
        $severity = strtolower((string) config('support.incident_banner.severity', 'warning'));

        return response()->json([
            'data' => [
                'enabled' => $enabled,
                'severity' => in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'warning',
                'message' => $enabled ? $message : null,
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
        $workspaceName = (string) DB::table('workspaces')->where('id', $workspaceId)->value('name');
        $inbox = trim((string) config('support.inbox_email'));

        if ($inbox === '') {
            return response()->json([
                'message' => 'Support email is temporarily unavailable. Please use the email address shown on this page.',
                'errorReference' => $reference,
            ], 503);
        }

        try {
            Mail::to($inbox)->send(new SupportRequestMail([
                ...$validated,
                'reference' => $reference,
                'email' => $email,
                'workspace_id' => $workspaceId,
                'workspace_name' => $workspaceName,
            ]));
        } catch (Throwable $exception) {
            Log::warning('Support request email failed', [
                'reference' => $reference,
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'We could not send the request. Please email support directly and include this reference.',
                'errorReference' => $reference,
            ], 503);
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
            'message' => 'Support request sent',
            'data' => ['reference' => $reference],
        ], 201);
    }
}
