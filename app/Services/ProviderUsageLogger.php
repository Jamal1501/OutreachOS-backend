<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderUsageLogger
{
    private const MAX_JSON_BYTES = 12000;

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
            'request_payload' => $this->safeJson($payload['request_payload'] ?? null),
            'response_payload' => $this->safeJson($payload['response_payload'] ?? null),
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
            'request_payload' => $this->safeJson($payload['request_payload'] ?? null),
            'response_payload' => $this->safeJson($payload['response_payload'] ?? null),
            'error_message' => $payload['error_message'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function safeJson(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $redacted = $this->redact($payload);
        $json = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return json_encode(['redacted' => true, 'reason' => 'json_encode_failed']);
        }

        if (strlen($json) > self::MAX_JSON_BYTES) {
            return json_encode([
                'redacted' => true,
                'reason' => 'payload_too_large',
                'bytes' => strlen($json),
                'preview' => substr($json, 0, self::MAX_JSON_BYTES),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $json;
    }

    private function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sensitiveKeys = [
            'authorization', 'apikey', 'api_key', 'token', 'access_token', 'refresh_token',
            'password', 'secret', 'service_role_key', 'email', 'contact_email', 'phone',
            'handle', 'username', 'user_name', 'full_name', 'name', 'bio', 'biography',
            'caption', 'comment', 'text', 'message', 'message_text', 'notes', 'url',
            'profile_url', 'avatar_url', 'image_url', 'video_url', 'thumbnail_url',
        ];

        $redacted = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string) $key);
            if (in_array($keyString, $sensitiveKeys, true) || str_contains($keyString, 'token') || str_contains($keyString, 'secret')) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            $redacted[$key] = $this->redact($item);
        }

        return $redacted;
    }
}
