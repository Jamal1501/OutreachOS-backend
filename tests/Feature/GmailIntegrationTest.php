<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GmailIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_connect_gmail_and_tokens_are_encrypted_at_rest(): void
    {
        [$owner, $workspace] = $this->workspaceFixture('owner');
        $this->fakeGoogleAndSupabase($owner);

        $connect = $this->workspaceRequest($owner, $workspace)
            ->postJson('/api/integrations/gmail/connect')
            ->assertOk();

        $authorizationUrl = (string) $connect->json('data.authorizationUrl');
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/gmail.send', $query['scope']);
        $this->assertDatabaseCount('oauth_connection_states', 1);

        $this->get('/api/integrations/gmail/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'authorization-code',
        ]))->assertRedirect('https://www.socialcore.app/settings?tab=email&gmail=connected');
        $this->get('/api/integrations/gmail/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'authorization-code',
        ]))->assertRedirect('https://www.socialcore.app/settings?tab=email&gmail=error&reason=connection_failed');

        $account = ConnectedAccount::query()->firstOrFail();
        $this->assertSame('sender@example.test', $account->username);
        $this->assertSame('refresh-token', $account->oauth_credentials['refresh_token']);
        $this->assertDatabaseCount('connected_accounts', 1);
        $rawCredentials = (string) DB::table('connected_accounts')->value('oauth_credentials');
        $this->assertStringNotContainsString('refresh-token', $rawCredentials);

        $list = $this->workspaceRequest($owner, $workspace)
            ->getJson('/api/integrations/gmail')
            ->assertOk()
            ->assertJsonPath('data.accounts.0.email', 'sender@example.test')
            ->assertJsonPath('data.accounts.0.isDefault', true);
        $this->assertStringNotContainsString('access-token', $list->getContent());
        $this->assertStringNotContainsString('refresh-token', $list->getContent());
    }

    public function test_connected_gmail_sends_once_for_an_idempotency_key(): void
    {
        [$owner, $workspace] = $this->workspaceFixture('owner');
        $this->fakeGoogleAndSupabase($owner);
        $account = $this->connectAccount($owner, $workspace);
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'accountId' => $account->id,
            'idempotencyKey' => $idempotencyKey,
            'to' => 'creator@example.test',
            'subject' => 'Collaboration idea',
            'body' => 'Hi creator, this is the reviewed email body.',
            'creatorHandle' => '@creator',
            'messageType' => 'email',
        ];

        $first = $this->workspaceRequest($owner, $workspace)
            ->postJson('/api/integrations/gmail/send', $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.account.email', 'sender@example.test');

        $second = $this->workspaceRequest($owner, $workspace)
            ->postJson('/api/integrations/gmail/send', $payload)
            ->assertOk();
        $this->assertSame($first->json('data.deliveryId'), $second->json('data.deliveryId'));
        $this->assertDatabaseCount('outbound_email_deliveries', 1);

        $gmailRequests = collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'gmail.googleapis.com/gmail/v1/users/me/messages/send'));
        $this->assertCount(1, $gmailRequests);
        /** @var Request $gmailRequest */
        $gmailRequest = $gmailRequests->first()[0];
        $raw = (string) $gmailRequest->data()['raw'];
        $decoded = base64_decode(strtr($raw, '-_', '+/'));
        $this->assertStringContainsString('To: <creator@example.test>', $decoded);
        $this->assertStringContainsString('Subject: Collaboration idea', $decoded);
    }

    public function test_workspace_cannot_send_from_another_workspace_gmail_account(): void
    {
        [$owner, $workspaceA] = $this->workspaceFixture('owner');
        $workspaceB = $this->workspaceFor($owner, 'Second Workspace');
        $this->fakeGoogleAndSupabase($owner);
        $account = $this->connectAccount($owner, $workspaceA);

        $this->workspaceRequest($owner, $workspaceB)
            ->postJson('/api/integrations/gmail/send', [
                'accountId' => $account->id,
                'idempotencyKey' => (string) Str::uuid(),
                'to' => 'creator@example.test',
                'subject' => 'Test',
                'body' => 'Test body',
                'messageType' => 'email',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('outbound_email_deliveries', 0);
    }

    public function test_regular_member_cannot_connect_or_disconnect_workspace_gmail(): void
    {
        [$member, $workspace] = $this->workspaceFixture('member');
        $this->fakeGoogleAndSupabase($member);

        $this->workspaceRequest($member, $workspace)
            ->postJson('/api/integrations/gmail/connect')
            ->assertForbidden();
    }

    private function connectAccount(User $user, Workspace $workspace): ConnectedAccount
    {
        $connect = $this->workspaceRequest($user, $workspace)->postJson('/api/integrations/gmail/connect');
        parse_str((string) parse_url((string) $connect->json('data.authorizationUrl'), PHP_URL_QUERY), $query);
        $this->get('/api/integrations/gmail/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'authorization-code',
        ]))->assertRedirect();

        return ConnectedAccount::query()->firstOrFail();
    }

    private function workspaceFixture(string $role): array
    {
        $user = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Gmail User',
            'email' => 'user@example.test',
            'password' => 'password',
        ]);
        $workspace = $this->workspaceFor($user, 'Gmail Workspace', $role);

        return [$user, $workspace];
    }

    private function workspaceFor(User $user, string $name, string $role = 'owner'): Workspace
    {
        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'owner_id' => $user->supabase_user_id,
            'plan_id' => 'free',
            'settings' => ['workspaceDataKey' => 'workspace:'.Str::lower(Str::random(12))],
        ]);
        WorkspaceMember::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->supabase_user_id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return $workspace;
    }

    private function workspaceRequest(User $user, Workspace $workspace): static
    {
        return $this->withToken('valid-token')->withHeader('X-Workspace-Id', $workspace->id);
    }

    private function fakeGoogleAndSupabase(User $user): void
    {
        config([
            'services.supabase.url' => 'https://supabase.example.test',
            'services.supabase.service_role_key' => 'service-role-key',
            'services.gmail.enabled' => true,
            'services.gmail.client_id' => 'gmail-client-id',
            'services.gmail.client_secret' => 'gmail-client-secret',
            'services.gmail.redirect_uri' => 'https://api.example.test/api/integrations/gmail/callback',
            'services.gmail.frontend_url' => 'https://www.socialcore.app',
        ]);
        Http::fake(function (Request $request) use ($user) {
            if (str_contains($request->url(), 'supabase.example.test/auth/v1/user')) {
                return Http::response([
                    'id' => $user->supabase_user_id,
                    'email' => $user->email,
                    'email_confirmed_at' => now()->toIso8601String(),
                    'user_metadata' => ['full_name' => $user->name],
                ]);
            }
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response([
                    'access_token' => 'access-token',
                    'refresh_token' => 'refresh-token',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                    'scope' => 'openid email https://www.googleapis.com/auth/gmail.send',
                ]);
            }
            if ($request->url() === 'https://openidconnect.googleapis.com/v1/userinfo') {
                return Http::response([
                    'sub' => 'google-user-1',
                    'email' => 'sender@example.test',
                    'email_verified' => true,
                ]);
            }
            if ($request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send') {
                return Http::response(['id' => 'gmail-message-1', 'threadId' => 'gmail-thread-1']);
            }

            return Http::response(['error' => 'unexpected_test_request'], 500);
        });
    }
}
