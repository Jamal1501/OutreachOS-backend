<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DataLifecycleService
{
    private const EXPORT_BUFFER_BYTES = 65536;

    private const EXPORT_CHUNK_SIZE = 100;

    public function scheduleWorkspaceDeletion(string $workspaceId, string $userId): array
    {
        $purgeAfter = now()->addDays(30);
        DB::table('data_deletion_requests')->updateOrInsert(
            ['type' => 'workspace', 'workspace_id' => $workspaceId, 'status' => 'scheduled'],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'requested_at' => now(),
                'purge_after' => $purgeAfter,
                'metadata' => json_encode(['recovery_days' => 30]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return ['purgeAfter' => $purgeAfter->toIso8601String(), 'recoveryDays' => 30];
    }

    public function scheduleAccountDeletion(string $userId): array
    {
        $purgeAfter = now()->addDays(30);
        DB::table('data_deletion_requests')->updateOrInsert(
            ['type' => 'account', 'user_id' => $userId, 'status' => 'scheduled'],
            [
                'id' => (string) Str::uuid(),
                'requested_at' => now(),
                'purge_after' => $purgeAfter,
                'metadata' => json_encode(['recovery_days' => 30]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        foreach (Workspace::query()->where('owner_id', $userId)->pluck('id') as $workspaceId) {
            $this->scheduleWorkspaceDeletion((string) $workspaceId, $userId);
        }

        return ['purgeAfter' => $purgeAfter->toIso8601String(), 'recoveryDays' => 30];
    }

    public function cancelAccountDeletion(string $userId): void
    {
        DB::table('data_deletion_requests')
            ->where('type', 'account')->where('user_id', $userId)->where('status', 'scheduled')
            ->update(['status' => 'canceled', 'canceled_at' => now(), 'updated_at' => now()]);

        foreach (Workspace::query()->where('owner_id', $userId)->get() as $workspace) {
            $this->cancelWorkspaceDeletion((string) $workspace->id);
            $settings = (array) ($workspace->settings ?? []);
            unset($settings['deletedAt'], $settings['deletedBy'], $settings['archivedAt'], $settings['archivedBy']);
            $workspace->settings = $settings;
            $workspace->save();
        }
    }

    public function cancelWorkspaceDeletion(string $workspaceId): void
    {
        DB::table('data_deletion_requests')
            ->where('type', 'workspace')->where('workspace_id', $workspaceId)->where('status', 'scheduled')
            ->update(['status' => 'canceled', 'canceled_at' => now(), 'updated_at' => now()]);
    }

    public function streamWorkspaceExport(string $workspaceId, callable $write): void
    {
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $projectIds = DB::table('projects')->where('workspace_id', $workspaceId)->pluck('id')->all();
        $projectTables = ['creators', 'creator_profiles', 'message_templates', 'tasks', 'outreach_events', 'discovery_runs', 'discovery_items', 'enrichment_jobs', 'connected_accounts'];
        $workspaceTables = ['workspace_members', 'workspace_invitations', 'workspace_audit_events', 'duplicate_links', 'learning_events', 'creator_relationship_events', 'ai_usage_logs', 'apify_usage_logs', 'workspace_usage_events', 'credit_purchases'];
        $billingTables = ['billing_accounts', 'workspace_subscriptions', 'workspace_credit_wallets'];
        $billingAccountId = (string) ($workspace->billing_account_id ?? '');
        $buffer = '';
        $emit = function (string $chunk) use (&$buffer, $write): void {
            $buffer .= $chunk;
            if (strlen($buffer) >= self::EXPORT_BUFFER_BYTES) {
                $write($buffer);
                $buffer = '';
            }
        };
        $flush = function () use (&$buffer, $write): void {
            if ($buffer !== '') {
                $write($buffer);
                $buffer = '';
            }
        };

        $emit('{"exportedAt":'.$this->encodeExportValue(now()->toIso8601String()));
        $emit(',"exportType":"workspace_customer_data"');
        $emit(',"retainedAfterDeletion":'.$this->encodeExportValue([
            'billing settlement records required by law',
            'minimal purge audit record',
        ]));
        $emit(',"workspace":'.$this->encodeExportValue([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'settings' => $workspace->settings,
        ]));
        $emit(',"projects":');
        $this->streamExportQuery(
            DB::table('projects')->where('workspace_id', $workspaceId),
            $emit,
        );
        $emit(',"data":{');

        $firstTable = true;
        $streamTable = function (string $table, $query) use (&$firstTable, $emit): void {
            if (! $firstTable) {
                $emit(',');
            }
            $firstTable = false;
            $emit($this->encodeExportValue($table).':');
            $this->streamExportQuery($query, $emit);
        };

        foreach ($projectTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'project_id')) {
                $query = DB::table($table)->whereIn('project_id', $projectIds);
                $streamTable($table, $query);
            }
        }

        foreach ($workspaceTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'workspace_id')) {
                $streamTable($table, DB::table($table)->where('workspace_id', $workspaceId));
            }
        }

        if ($billingAccountId !== '') {
            foreach ($billingTables as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'billing_account_id')) {
                    $streamTable($table, DB::table($table)->where('billing_account_id', $billingAccountId));
                }
            }
        }

        $emit('}}');
        $flush();
    }

    private function streamExportQuery($query, callable $emit): void
    {
        $emit('[');
        $firstRow = true;

        foreach ($query->lazyById(self::EXPORT_CHUNK_SIZE, column: 'id') as $row) {
            if (! $firstRow) {
                $emit(',');
            }
            $firstRow = false;
            $emit($this->encodeExportValue((array) $row));
        }

        $emit(']');
    }

    private function encodeExportValue(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    public function purgeDue(): int
    {
        $requests = DB::table('data_deletion_requests')
            ->where('status', 'scheduled')->where('purge_after', '<=', now())->orderBy('requested_at')->get();
        $completed = 0;
        foreach ($requests as $request) {
            try {
                $request->type === 'workspace'
                    ? $this->purgeWorkspace((string) $request->workspace_id)
                    : $this->purgeAccount((string) $request->user_id);
                DB::table('data_deletion_requests')->where('id', $request->id)
                    ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
                $completed++;
            } catch (Throwable $exception) {
                $metadata = is_string($request->metadata ?? null) ? (json_decode($request->metadata, true) ?: []) : (array) ($request->metadata ?? []);
                $metadata['purge_attempts'] = ((int) ($metadata['purge_attempts'] ?? 0)) + 1;
                $metadata['last_error'] = mb_substr($exception->getMessage(), 0, 500);
                $metadata['last_attempt_at'] = now()->toIso8601String();
                DB::table('data_deletion_requests')->where('id', $request->id)->update([
                    'metadata' => json_encode($metadata),
                    'updated_at' => now(),
                ]);
                Log::error('Scheduled data purge failed and will be retried', [
                    'requestId' => $request->id,
                    'type' => $request->type,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $completed;
    }

    private function purgeWorkspace(string $workspaceId): void
    {
        DB::transaction(function () use ($workspaceId) {
            DB::table('projects')->where('workspace_id', $workspaceId)->delete();
            foreach (['duplicate_links', 'learning_events', 'creator_relationship_events', 'ai_usage_logs', 'apify_usage_logs', 'workspace_invitations', 'workspace_members'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'workspace_id')) {
                    DB::table($table)->where('workspace_id', $workspaceId)->delete();
                }
            }
            if (Schema::hasTable('workspace_audit_events')) {
                DB::table('workspace_audit_events')->where('workspace_id', $workspaceId)->delete();
                DB::table('workspace_audit_events')->insert([
                    'id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'event_type' => 'workspace_purge_completed',
                    'subject_type' => 'workspace', 'subject_id' => $workspaceId,
                    'metadata' => json_encode(['customer_content_purged' => true]), 'created_at' => now(),
                ]);
            }
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace) {
                $workspace->name = 'Deleted workspace';
                $workspace->slug = 'deleted-'.Str::lower(Str::random(12));
                $workspace->owner_id = 'deleted';
                $workspace->settings = ['purgedAt' => now()->toIso8601String()];
                $workspace->save();
            }
        });
    }

    private function purgeAccount(string $userId): void
    {
        $url = rtrim((string) config('services.supabase.url'), '/');
        $serviceKey = (string) config('services.supabase.service_role_key');
        if ($url === '' || $serviceKey === '') {
            throw new RuntimeException('Supabase account deletion is not configured.');
        }

        $response = Http::retry(3, 250)
            ->timeout(15)
            ->withHeaders(['apikey' => $serviceKey, 'Authorization' => 'Bearer '.$serviceKey])
            ->delete($url.'/auth/v1/admin/users/'.rawurlencode($userId));

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Supabase account deletion failed with status '.$response->status().'.');
        }

        DB::transaction(function () use ($userId) {
            DB::table('workspace_members')->where('user_id', $userId)->delete();
            DB::table('users')->where('supabase_user_id', $userId)->delete();
        });
    }
}
