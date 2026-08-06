<?php

namespace App\Http\Controllers;

use App\Models\ConnectedAccount;
use App\Models\Project;
use App\Services\GmailIntegrationService;
use App\Services\ObservabilityService;
use App\Services\ProjectResolverService;
use App\Services\WorkspaceContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class GmailIntegrationController extends Controller
{
    public function __construct(
        private GmailIntegrationService $gmail,
        private WorkspaceContextService $workspaceContext,
        private ProjectResolverService $projects,
        private ObservabilityService $observability,
    ) {}

    public function index(Request $request)
    {
        return response()->json([
            'message' => 'Gmail integrations loaded',
            'data' => $this->gmail->list($this->project($request)),
        ]);
    }

    public function connect(Request $request)
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $userId = (string) $request->attributes->get('supabase_user_id');

        return response()->json([
            'message' => 'Gmail authorization ready',
            'data' => [
                'authorizationUrl' => $this->gmail->authorizationUrl($workspaceId, $this->project($request), $userId),
            ],
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = trim((string) $request->query('state'));
        $code = trim((string) $request->query('code'));
        $oauthError = trim((string) $request->query('error'));

        if ($oauthError !== '') {
            return $this->frontendRedirect('error', 'access_denied');
        }

        try {
            if ($code === '') {
                throw new \RuntimeException('Missing authorization code.');
            }
            $this->gmail->completeAuthorization($state, $code);

            return $this->frontendRedirect('connected');
        } catch (Throwable $exception) {
            Log::warning('Gmail OAuth callback failed', [
                'error' => $exception->getMessage(),
            ]);

            return $this->frontendRedirect('error', 'connection_failed');
        }
    }

    public function makeDefault(Request $request, string $id)
    {
        $project = $this->project($request);
        $account = $this->account($project, $id);
        if ($account->status !== 'connected') {
            return response()->json(['message' => 'Reconnect this Gmail account before selecting it.'], 422);
        }
        $this->gmail->setDefault($project, $account);
        $this->observability->audit(
            (string) $request->attributes->get('workspace_id'),
            'gmail_default_account_changed',
            'connected_account',
            (string) $account->id,
            [],
            (string) $request->attributes->get('supabase_user_id'),
        );

        return response()->json(['message' => 'Default Gmail sender updated']);
    }

    public function disconnect(Request $request, string $id)
    {
        $project = $this->project($request);
        $account = $this->account($project, $id);
        $this->gmail->disconnect(
            (string) $request->attributes->get('workspace_id'),
            $project,
            $account,
            (string) $request->attributes->get('supabase_user_id'),
        );

        return response()->json(['message' => 'Gmail account disconnected']);
    }

    public function send(Request $request)
    {
        $validated = $request->validate([
            'accountId' => ['required', 'uuid'],
            'idempotencyKey' => ['required', 'uuid'],
            'to' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'max:998'],
            'body' => ['required', 'string', 'max:100000'],
            'creatorHandle' => ['nullable', 'string', 'max:255'],
            'taskId' => ['nullable', 'string', 'max:255'],
            'messageType' => ['nullable', Rule::in(['email'])],
        ]);

        return response()->json([
            'message' => 'Email sent with Gmail',
            'data' => $this->gmail->send(
                $this->project($request),
                (string) $request->attributes->get('workspace_id'),
                (string) $request->attributes->get('supabase_user_id'),
                $validated,
            ),
        ]);
    }

    private function project(Request $request): Project
    {
        $workbookId = $this->workspaceContext->resolveWorkbookId($request);

        return $this->projects->resolveByWorkbookId($workbookId);
    }

    private function account(Project $project, string $id): ConnectedAccount
    {
        return ConnectedAccount::query()
            ->where('project_id', $project->id)
            ->where('platform', 'email')
            ->where('provider', 'google')
            ->findOrFail($id);
    }

    private function frontendRedirect(string $status, ?string $reason = null): RedirectResponse
    {
        $base = rtrim((string) config('services.gmail.frontend_url'), '/');
        $params = ['tab' => 'email', 'gmail' => $status];
        if ($reason) {
            $params['reason'] = $reason;
        }

        return redirect()->away($base.'/settings?'.http_build_query($params));
    }
}
