<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiGatewayService
{
    public function __construct(
        private ProviderUsageLogger $usageLogger,
        private WorkspaceBillingService $billing,
    ) {
    }

    public function structured(string $systemPrompt, string $userPrompt, string $toolName, string $description, array $schema, float $temperature = 0.2): array
    {
        $apiKey = (string) config('services.ai.openai_key');
        $model = (string) config('services.ai.openai_model');
        $workspaceId = (string) request()?->attributes->get('workspace_id');
        $usageReservationId = null;

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

        try {
            $usageReservation = $this->billing->reserveAi($workspaceId, $toolName, [
                'model' => $model,
                'temperature' => $temperature,
            ]);
            $usageReservationId = $usageReservation['usage_event_id'] ?? null;
        } catch (InsufficientCreditsException $e) {
            throw new RuntimeException($e->getMessage());
        }

        $response = Http::timeout((int) config('services.ai.timeout', 60))
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', $requestPayload);

        if ($response->status() === 429) {
            if ($usageReservationId) {
                $this->billing->refundReservation($usageReservationId, 'AI rate limit exceeded');
            }

            $this->usageLogger->logAi([
                'model' => $model,
                'operation' => $toolName,
                'request_payload' => $this->redactRequestPayload($requestPayload),
                'error_message' => 'AI rate limit exceeded',
            ]);

            throw new RuntimeException('AI rate limit exceeded. Try again in a minute.');
        }

        if (!$response->successful()) {
            if ($usageReservationId) {
                $this->billing->refundReservation($usageReservationId, 'AI request failed', [
                    'status' => $response->status(),
                ]);
            }

            $this->usageLogger->logAi([
                'model' => $model,
                'operation' => $toolName,
                'request_payload' => $this->redactRequestPayload($requestPayload),
                'error_message' => 'AI request failed: ' . $response->status() . ' ' . $response->body(),
            ]);

            throw new RuntimeException('AI request failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json();
        $usage = (array) ($payload['usage'] ?? []);
        $estimatedCost = $this->estimateOpenAiCostUsd($model, (int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0));

        $arguments = Arr::get($payload, 'choices.0.message.tool_calls.0.function.arguments');

        if (!is_string($arguments) || trim($arguments) === '') {
            if ($usageReservationId) {
                $this->billing->refundReservation($usageReservationId, 'AI response missing structured output', [
                    'openai_id' => $payload['id'] ?? null,
                ]);
            }
            throw new RuntimeException('AI did not return a structured payload.');
        }

        $decoded = json_decode($arguments, true);
        if (!is_array($decoded)) {
            if ($usageReservationId) {
                $this->billing->refundReservation($usageReservationId, 'AI response contained invalid structured output', [
                    'openai_id' => $payload['id'] ?? null,
                ]);
            }
            throw new RuntimeException('AI returned invalid structured JSON.');
        }

        if ($usageReservationId) {
            $this->billing->consumeReservation($usageReservationId, providerCostUsd: $estimatedCost, metadata: [
                'openai_id' => $payload['id'] ?? null,
                'usage' => $usage,
                'provider_cost_source' => 'openai_token_estimate',
                'structured_output_validated' => true,
            ], referenceId: (string) ($payload['id'] ?? ''));
        }

        $this->usageLogger->logAi([
            'model' => $model,
            'operation' => $toolName,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'estimated_cost_usd' => $estimatedCost,
            'request_payload' => $this->redactRequestPayload($requestPayload),
            'response_payload' => [
                'id' => $payload['id'] ?? null,
                'usage' => $usage,
            ],
        ]);

        return $decoded;
    }

    private function redactRequestPayload(array $requestPayload): array
    {
        $messages = (array) ($requestPayload["messages"] ?? []);
        $messageSummaries = [];

        foreach ($messages as $message) {
            $content = (string) ($message["content"] ?? "");
            $messageSummaries[] = [
                "role" => $message["role"] ?? null,
                "content_sha256" => $content !== "" ? hash("sha256", $content) : null,
                "content_length" => strlen($content),
            ];
        }

        return [
            "model" => $requestPayload["model"] ?? null,
            "temperature" => $requestPayload["temperature"] ?? null,
            "tool_choice" => $requestPayload["tool_choice"]["function"]["name"] ?? null,
            "tools" => array_map(fn ($tool) => [
                "type" => $tool["type"] ?? null,
                "function" => [
                    "name" => $tool["function"]["name"] ?? null,
                    "description" => $tool["function"]["description"] ?? null,
                ],
            ], (array) ($requestPayload["tools"] ?? [])),
            "messages" => $messageSummaries,
            "redacted" => true,
            "redaction_reason" => "Prompt content may contain customer, creator, or project personal data.",
        ];
    }

    private function estimateOpenAiCostUsd(string $model, int $promptTokens, int $completionTokens): float
    {
        $normalized = strtolower(trim($model));

        $pricing = match ($normalized) {
            'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
            'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
            default => ['input' => 0.40, 'output' => 1.60],
        };

        $inputCost = ($promptTokens / 1_000_000) * $pricing['input'];
        $outputCost = ($completionTokens / 1_000_000) * $pricing['output'];

        return round($inputCost + $outputCost, 6);
    }
}
