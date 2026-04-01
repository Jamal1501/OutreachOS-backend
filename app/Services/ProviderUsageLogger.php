<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderUsageLogger
{
    public function logAi(array $payload): void
    {
        if (!DB::getSchemaBuilder()->hasTable('ai_usage_logs')) {
            return;
        }

        DB::table('ai_usage_logs')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => request()?->attributes->get('workspace_id'),
            'project_id' => $payload['project_id'] ?? null,
            'user_id' => request()?->attributes->get('auth_user_id'),
            'supabase_user_id' => request()?->attributes->get('supabase_user_id'),
            'provider' => 'openai',
            'model' => $payload['model'] ?? null,
            'operation' => $payload['operation'] ?? null,
            'prompt_tokens' => $payload['prompt_tokens'] ?? null,
            'completion_tokens' => $payload['completion_tokens'] ?? null,
            'total_tokens' => $payload['total_tokens'] ?? null,
            'estimated_cost_usd' => $payload['estimated_cost_usd'] ?? null,
            'request_payload' => isset($payload['request_payload']) ? json_encode($payload['request_payload']) : null,
            'response_payload' => isset($payload['response_payload']) ? json_encode($payload['response_payload']) : null,
            'error_message' => $payload['error_message'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function logApify(array $payload): void
    {
        if (!DB::getSchemaBuilder()->hasTable('apify_usage_logs')) {
            return;
        }

        DB::table('apify_usage_logs')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => request()?->attributes->get('workspace_id'),
            'project_id' => $payload['project_id'] ?? null,
            'user_id' => request()?->attributes->get('auth_user_id'),
            'supabase_user_id' => request()?->attributes->get('supabase_user_id'),
            'actor_id' => $payload['actor_id'] ?? null,
            'actor_key' => $payload['actor_key'] ?? null,
            'run_id' => $payload['run_id'] ?? null,
            'dataset_id' => $payload['dataset_id'] ?? null,
            'status' => $payload['status'] ?? null,
            'max_total_charge_usd' => $payload['max_total_charge_usd'] ?? null,
            'estimated_cost_usd' => $payload['estimated_cost_usd'] ?? null,
            'request_payload' => isset($payload['request_payload']) ? json_encode($payload['request_payload']) : null,
            'response_payload' => isset($payload['response_payload']) ? json_encode($payload['response_payload']) : null,
            'error_message' => $payload['error_message'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
