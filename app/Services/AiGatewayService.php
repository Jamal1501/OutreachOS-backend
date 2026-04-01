<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiGatewayService
{
    public function __construct(private ProviderUsageLogger $usageLogger)
    {
    }

    public function structured(string $systemPrompt, string $userPrompt, string $toolName, string $description, array $schema, float $temperature = 0.2): array
    {
        $apiKey = (string) config('services.ai.openai_key');
        $model = (string) config('services.ai.openai_model');

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured on the backend.');
        }

        if ($model === '') {
            throw new RuntimeException('OPENAI_MODEL is not configured on the backend.');
        }

        $requestPayload = [
            'model' => $model,
            'temperature' => $temperature,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => $toolName,
                    'description' => $description,
                    'parameters' => $schema,
                ],
            ]],
            'tool_choice' => [
                'type' => 'function',
                'function' => ['name' => $toolName],
            ],
        ];

        $response = Http::timeout((int) config('services.ai.timeout', 60))
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', $requestPayload);

        if ($response->status() === 429) {
            $this->usageLogger->logAi([
                'model' => $model,
                'operation' => $toolName,
                'request_payload' => $requestPayload,
                'error_message' => 'AI rate limit exceeded',
            ]);

            throw new RuntimeException('AI rate limit exceeded. Try again in a minute.');
        }

        if (!$response->successful()) {
            $this->usageLogger->logAi([
                'model' => $model,
                'operation' => $toolName,
                'request_payload' => $requestPayload,
                'error_message' => 'AI request failed: ' . $response->status() . ' ' . $response->body(),
            ]);

            throw new RuntimeException('AI request failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json();
        $usage = (array) ($payload['usage'] ?? []);

        $this->usageLogger->logAi([
            'model' => $model,
            'operation' => $toolName,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'request_payload' => $requestPayload,
            'response_payload' => [
                'id' => $payload['id'] ?? null,
                'usage' => $usage,
            ],
        ]);
        $arguments = Arr::get($payload, 'choices.0.message.tool_calls.0.function.arguments');

        if (!is_string($arguments) || trim($arguments) === '') {
            throw new RuntimeException('AI did not return a structured payload.');
        }

        $decoded = json_decode($arguments, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI returned invalid structured JSON.');
        }

        return $decoded;
    }
}
