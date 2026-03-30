<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiGatewayService
{
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

        $response = Http::timeout((int) config('services.ai.timeout', 60))
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
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
            ]);

        if ($response->status() === 429) {
            throw new RuntimeException('AI rate limit exceeded. Try again in a minute.');
        }

        if (!$response->successful()) {
            throw new RuntimeException('AI request failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json();
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
