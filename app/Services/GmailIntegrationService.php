<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\Project;
use App\Models\WorkspaceMember;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class GmailIntegrationService
{
    private const PROVIDER = 'google';

    private const PLATFORM = 'email';

    private const SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    private const IDENTITY_SCOPES = ['openid', 'email'];

    public function __construct(private ObservabilityService $observability) {}

    public function configured(): bool
    {
        return (bool) config('services.gmail.enabled')
            && trim((string) config('services.gmail.client_id')) !== ''
            && trim((string) config('services.gmail.client_secret')) !== ''
            && trim((string) config('services.gmail.redirect_uri')) !== '';
    }

    public function list(Project $project): array
    {
        $accounts = ConnectedAccount::query()
            ->where('project_id', $project->id)
            ->where('platform', self::PLATFORM)
            ->where('provider', self::PROVIDER)
            ->orderByRaw("CASE WHEN status = 'connected' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->get();

        return [
            'configured' => $this->configured(),
            'accounts' => $accounts->map(fn (ConnectedAccount $account) => $this->publicAccount($account))->values()->all(),
        ];
    }

    public function authorizationUrl(string $workspaceId, Project $project, string $userId): string
    {
        $this->assertConfigured();

        $state = Str::random(64);
        DB::table('oauth_connection_states')->insertOrIgnore([
            'state_hash' => hash('sha256', $state),
            'workspace_id' => $workspaceId,
            'project_id' => $project->id,
            'user_id' => $userId,
            'provider' => self::PROVIDER,
            'expires_at' => now()->addMinutes(max(5, (int) config('services.gmail.oauth_state_ttl_minutes', 10))),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('oauth_connection_states')
            ->where('expires_at', '<', now()->subHour())
            ->delete();

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => (string) config('services.gmail.client_id'),
            'redirect_uri' => (string) config('services.gmail.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', [...self::IDENTITY_SCOPES, self::SEND_SCOPE]),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(string $state, string $code): ConnectedAccount
    {
        $this->assertConfigured();
        $connectionState = $this->consumeState($state);

        $membership = WorkspaceMember::query()
            ->where('workspace_id', $connectionState->workspace_id)
            ->where('user_id', $connectionState->user_id)
            ->whereIn('role', ['owner', 'admin'])
            ->first();
        if (! $membership) {
            throw new RuntimeException('Workspace access changed before Gmail could be connected.');
        }

        $project = Project::query()
            ->where('workspace_id', $connectionState->workspace_id)
            ->find($connectionState->project_id);
        if (! $project) {
            throw new RuntimeException('The workspace project is no longer available.');
        }

        $tokens = $this->exchangeAuthorizationCode($code);
        $scope = preg_split('/\s+/', trim((string) ($tokens['scope'] ?? ''))) ?: [];
        if (! in_array(self::SEND_SCOPE, $scope, true)) {
            throw new RuntimeException('Gmail send permission was not granted.');
        }

        $accessToken = trim((string) ($tokens['access_token'] ?? ''));
        $identity = Http::timeout(15)
            ->acceptJson()
            ->withToken($accessToken)
            ->get('https://openidconnect.googleapis.com/v1/userinfo')
            ->throw()
            ->json();

        $externalId = trim((string) ($identity['sub'] ?? ''));
        $email = strtolower(trim((string) ($identity['email'] ?? '')));
        if ($externalId === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Google did not return a valid Gmail identity.');
        }

        $account = ConnectedAccount::query()
            ->where('project_id', $project->id)
            ->where('platform', self::PLATFORM)
            ->where('provider', self::PROVIDER)
            ->where('external_account_id', $externalId)
            ->first() ?: new ConnectedAccount;

        $existingCredentials = (array) ($account->oauth_credentials ?? []);
        $refreshToken = trim((string) ($tokens['refresh_token'] ?? $existingCredentials['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new RuntimeException('Google did not return an offline refresh token. Please reconnect and approve access again.');
        }

        $expiresAt = now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 3600)));
        $account->fill([
            'project_id' => $project->id,
            'platform' => self::PLATFORM,
            'provider' => self::PROVIDER,
            'external_account_id' => $externalId,
            'username' => $email,
            'status' => 'connected',
            'scopes' => $scope,
            'credentials_reference' => 'database_encrypted',
            'oauth_credentials' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => (string) ($tokens['token_type'] ?? 'Bearer'),
            ],
            'connected_by_user_id' => (string) $connectionState->user_id,
            'token_expires_at' => $expiresAt,
            'last_synced_at' => now(),
            'last_error' => null,
            'metadata' => array_merge((array) ($account->metadata ?? []), [
                'is_default' => true,
                'email_verified' => (bool) ($identity['email_verified'] ?? false),
            ]),
        ]);
        $account->save();

        $this->setDefault($project, $account);
        $this->observability->audit(
            (string) $connectionState->workspace_id,
            'gmail_account_connected',
            'connected_account',
            (string) $account->id,
            ['provider' => self::PROVIDER, 'email_domain' => Str::after($email, '@')],
            (string) $connectionState->user_id,
        );

        return $account->fresh();
    }

    public function setDefault(Project $project, ConnectedAccount $selected): void
    {
        $accounts = ConnectedAccount::query()
            ->where('project_id', $project->id)
            ->where('platform', self::PLATFORM)
            ->where('provider', self::PROVIDER)
            ->get();

        foreach ($accounts as $account) {
            $metadata = (array) ($account->metadata ?? []);
            $metadata['is_default'] = $account->id === $selected->id;
            $account->metadata = $metadata;
            $account->save();
        }
    }

    public function disconnect(string $workspaceId, Project $project, ConnectedAccount $account, string $userId): void
    {
        $credentials = (array) ($account->oauth_credentials ?? []);
        $otherConnections = ConnectedAccount::query()
            ->where('provider', self::PROVIDER)
            ->where('external_account_id', $account->external_account_id)
            ->where('status', 'connected')
            ->where('id', '<>', $account->id)
            ->exists();

        if (! $otherConnections) {
            $token = trim((string) ($credentials['refresh_token'] ?? $credentials['access_token'] ?? ''));
            if ($token !== '') {
                try {
                    Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
                } catch (Throwable) {
                    // Local disconnection must still remove access even when Google is temporarily unavailable.
                }
            }
        }

        $account->fill([
            'status' => 'disconnected',
            'oauth_credentials' => null,
            'credentials_reference' => null,
            'token_expires_at' => null,
            'last_error' => null,
            'metadata' => array_merge((array) ($account->metadata ?? []), ['is_default' => false]),
        ])->save();

        $replacement = ConnectedAccount::query()
            ->where('project_id', $project->id)
            ->where('platform', self::PLATFORM)
            ->where('provider', self::PROVIDER)
            ->where('status', 'connected')
            ->orderByDesc('last_used_at')
            ->first();
        if ($replacement) {
            $this->setDefault($project, $replacement);
        }

        $this->observability->audit(
            $workspaceId,
            'gmail_account_disconnected',
            'connected_account',
            (string) $account->id,
            ['provider' => self::PROVIDER],
            $userId,
        );
    }

    public function send(Project $project, string $workspaceId, string $userId, array $payload): array
    {
        $this->assertConfigured();
        $account = ConnectedAccount::query()
            ->where('project_id', $project->id)
            ->where('platform', self::PLATFORM)
            ->where('provider', self::PROVIDER)
            ->where('status', 'connected')
            ->find($payload['accountId']);
        if (! $account) {
            throw new HttpException(422, 'The selected Gmail account is not connected to this workspace.');
        }

        $deliveryId = (string) Str::uuid();
        try {
            DB::table('outbound_email_deliveries')->insertOrIgnore([
                'id' => $deliveryId,
                'workspace_id' => $workspaceId,
                'project_id' => $project->id,
                'connected_account_id' => $account->id,
                'idempotency_key' => $payload['idempotencyKey'],
                'sent_by_user_id' => $userId,
                'recipient_email' => strtolower($payload['to']),
                'subject' => $payload['subject'],
                'status' => 'sending',
                'metadata' => json_encode([
                    'creator_handle' => $payload['creatorHandle'] ?? null,
                    'task_id' => $payload['taskId'] ?? null,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $existing = DB::table('outbound_email_deliveries')
                ->where('workspace_id', $workspaceId)
                ->where('idempotency_key', $payload['idempotencyKey'])
                ->first();
            if (! $existing) {
                throw $exception;
            }
            if ($existing?->status === 'sent') {
                return $this->deliveryResult($existing, $account);
            }
            throw new ConflictHttpException('This email send is already being processed. Do not send it again.');
        }

        try {
            $accessToken = $this->accessToken($account);
            $response = $this->sendRequest($accessToken, $account, $payload);
            if ($response->status() === 401) {
                $accessToken = $this->refreshAccessToken($account, true);
                $response = $this->sendRequest($accessToken, $account, $payload);
            }
            if (! $response->successful()) {
                $status = $response->status();
                DB::table('outbound_email_deliveries')->where('id', $deliveryId)->update([
                    'status' => $status >= 500 ? 'uncertain' : 'failed',
                    'error_code' => 'gmail_http_'.$status,
                    'updated_at' => now(),
                ]);
                throw new HttpException(502, $status >= 500
                    ? 'Gmail did not confirm whether the email was sent. Check Gmail Sent before trying again.'
                    : 'Gmail rejected the email. Reconnect the mailbox or review the recipient and try again.');
            }

            $result = $response->json();
            DB::table('outbound_email_deliveries')->where('id', $deliveryId)->update([
                'status' => 'sent',
                'provider_message_id' => $result['id'] ?? null,
                'provider_thread_id' => $result['threadId'] ?? null,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
            $account->fill(['last_used_at' => now(), 'last_error' => null])->save();

            $this->observability->audit(
                $workspaceId,
                'gmail_message_sent',
                'outbound_email_delivery',
                $deliveryId,
                [
                    'connected_account_id' => (string) $account->id,
                    'recipient_domain' => Str::after(strtolower($payload['to']), '@'),
                    'task_id' => $payload['taskId'] ?? null,
                ],
                $userId,
            );

            return $this->deliveryResult(DB::table('outbound_email_deliveries')->find($deliveryId), $account);
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            DB::table('outbound_email_deliveries')->where('id', $deliveryId)->update([
                'status' => 'uncertain',
                'error_code' => 'gmail_transport_error',
                'updated_at' => now(),
            ]);
            throw new HttpException(502, 'Gmail did not confirm whether the email was sent. Check Gmail Sent before trying again.', $exception);
        }
    }

    public function publicAccount(ConnectedAccount $account): array
    {
        return [
            'id' => (string) $account->id,
            'email' => (string) $account->username,
            'status' => (string) $account->status,
            'isDefault' => (bool) (($account->metadata ?? [])['is_default'] ?? false),
            'connectedAt' => $account->created_at?->toIso8601String(),
            'lastUsedAt' => $account->last_used_at?->toIso8601String(),
            'lastError' => $account->last_error,
        ];
    }

    private function consumeState(string $state): object
    {
        if ($state === '') {
            throw new RuntimeException('Missing OAuth state.');
        }

        return DB::transaction(function () use ($state) {
            $record = DB::table('oauth_connection_states')
                ->where('state_hash', hash('sha256', $state))
                ->lockForUpdate()
                ->first();
            if (! $record || $record->consumed_at || now()->greaterThan($record->expires_at)) {
                throw new RuntimeException('This Gmail connection request expired or was already used.');
            }
            DB::table('oauth_connection_states')->where('state_hash', $record->state_hash)->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

            return $record;
        });
    }

    private function exchangeAuthorizationCode(string $code): array
    {
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => (string) config('services.gmail.client_id'),
            'client_secret' => (string) config('services.gmail.client_secret'),
            'code' => $code,
            'redirect_uri' => (string) config('services.gmail.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Google rejected the authorization code.');
        }

        return (array) $response->json();
    }

    private function accessToken(ConnectedAccount $account): string
    {
        $credentials = (array) ($account->oauth_credentials ?? []);
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        if ($accessToken !== '' && $account->token_expires_at?->isAfter(now()->addMinute())) {
            return $accessToken;
        }

        return $this->refreshAccessToken($account);
    }

    private function refreshAccessToken(ConnectedAccount $account, bool $force = false): string
    {
        if (! $force) {
            $credentials = (array) ($account->oauth_credentials ?? []);
            $current = trim((string) ($credentials['access_token'] ?? ''));
            if ($current !== '' && $account->token_expires_at?->isAfter(now()->addMinute())) {
                return $current;
            }
        }

        $credentials = (array) ($account->oauth_credentials ?? []);
        $refreshToken = trim((string) ($credentials['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            $this->requireReauthorization($account, 'missing_refresh_token');
        }

        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => (string) config('services.gmail.client_id'),
            'client_secret' => (string) config('services.gmail.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        if (! $response->successful()) {
            $this->requireReauthorization($account, 'refresh_rejected');
        }

        $tokens = (array) $response->json();
        $accessToken = trim((string) ($tokens['access_token'] ?? ''));
        if ($accessToken === '') {
            $this->requireReauthorization($account, 'refresh_missing_access_token');
        }

        $account->fill([
            'oauth_credentials' => array_merge($credentials, ['access_token' => $accessToken]),
            'token_expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 3600))),
            'status' => 'connected',
            'last_error' => null,
        ])->save();

        return $accessToken;
    }

    private function requireReauthorization(ConnectedAccount $account, string $reason): never
    {
        $account->fill([
            'status' => 'reauthorization_required',
            'last_error' => 'Gmail authorization expired. Reconnect this account.',
            'metadata' => array_merge((array) ($account->metadata ?? []), ['reauthorization_reason' => $reason]),
        ])->save();
        throw new HttpException(422, 'Gmail authorization expired. Reconnect this account before sending.');
    }

    private function sendRequest(string $accessToken, ConnectedAccount $account, array $payload): Response
    {
        return Http::timeout(20)
            ->acceptJson()
            ->asJson()
            ->withToken($accessToken)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $this->rawMessage((string) $account->username, $payload['to'], $payload['subject'], $payload['body']),
            ]);
    }

    private function rawMessage(string $from, string $to, string $subject, string $body): string
    {
        $safeSubject = str_replace(["\r", "\n"], ' ', trim($subject));
        $encodedSubject = mb_encode_mimeheader($safeSubject, 'UTF-8', 'B', "\r\n");
        $mime = implode("\r\n", [
            'From: <'.$from.'>',
            'To: <'.$to.'>',
            'Subject: '.$encodedSubject,
            'Date: '.gmdate(DATE_RFC2822),
            'Message-ID: <'.Str::uuid().'@socialcore.app>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n")),
        ]);

        return rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
    }

    private function sendResultAccount(ConnectedAccount $account): array
    {
        return ['id' => (string) $account->id, 'email' => (string) $account->username];
    }

    private function deliveryResult(object $delivery, ConnectedAccount $account): array
    {
        return [
            'deliveryId' => (string) $delivery->id,
            'status' => (string) $delivery->status,
            'gmailMessageId' => $delivery->provider_message_id,
            'gmailThreadId' => $delivery->provider_thread_id,
            'sentAt' => $delivery->sent_at,
            'account' => $this->sendResultAccount($account),
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new HttpException(503, 'Gmail integration is not configured yet.');
        }
    }
}
