<?php

namespace App\Services;

class AiPersonalizationService
{
    public function __construct(private AiGatewayService $ai)
    {
    }

    public function personalize(array $payload): array
    {
        $creator = (array) ($payload['creator'] ?? []);
        $template = trim((string) ($payload['template'] ?? ''));
        $stage = trim((string) ($payload['stage'] ?? ''));
        $projectContext = trim((string) ($payload['projectContext'] ?? ''));
        $templateContext = (array) ($payload['templateContext'] ?? []);
        $replyContext = (array) ($payload['replyContext'] ?? []);
        $taskContext = (array) ($payload['taskContext'] ?? []);
        $previousMessage = trim((string) ($payload['previousMessage'] ?? ''));
        $conversationGoal = trim((string) ($payload['conversationGoal'] ?? ''));
        $messageType = trim((string) ($payload['messageType'] ?? '')) ?: $this->defaultMessageType((string) ($creator['platform'] ?? 'instagram'));

        $schema = [
            'type' => 'object',
            'properties' => [
                'personalizedMessage' => ['type' => 'string'],
                'personalizationNotes' => ['type' => 'string'],
                'fitScore' => ['type' => 'number'],
                'confidenceScore' => ['type' => 'number'],
                'toneUsed' => ['type' => 'string'],
                'messageType' => ['type' => 'string'],
                'analysis' => [
                    'type' => 'object',
                    'properties' => [
                        'creatorSummary' => ['type' => 'string'],
                        'styleSignals' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'audienceSignals' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'proofPoints' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'personalizationHooks' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'risksToAvoid' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'recommendedAngle' => ['type' => 'string'],
                        'toneUsed' => ['type' => 'string'],
                        'fitScore' => ['type' => 'number'],
                    ],
                    'required' => ['creatorSummary', 'styleSignals', 'audienceSignals', 'proofPoints', 'personalizationHooks', 'risksToAvoid', 'recommendedAngle', 'toneUsed', 'fitScore'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['personalizedMessage', 'personalizationNotes', 'fitScore', 'confidenceScore', 'toneUsed', 'messageType', 'analysis'],
            'additionalProperties' => false,
        ];

        $systemPrompt = 'You write creator outreach that sounds observant and human, not templated. Keep claims grounded in the provided data. Never invent details. No fake intimacy, no cringe flattery, no spammy mass-DM language. Keep the offer concrete and short. Use the project context to adapt voice, audience fit, and positioning. A teen beauty brand should not sound like a premium furniture store, and vice versa.';

        $userPrompt = "Project context:
" . ($projectContext !== '' ? $projectContext : 'General creator outreach')
            . "

Stage: " . ($stage !== '' ? $stage : 'unspecified')
            . "
Message type: {$messageType}"
            . (!empty($taskContext) ? "

Task context:
" . json_encode($taskContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '')
            . "

Creator profile:
" . json_encode($creator, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . "

Template context:
" . json_encode($templateContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . "

Base template:
{$template}"
            . ($previousMessage !== '' ? "

Previous message:
{$previousMessage}" : '')
            . (!empty($replyContext) ? "

Reply context:
" . json_encode($replyContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '')
            . ($conversationGoal !== '' ? "

Conversation goal:
{$conversationGoal}" : '')
            . "

Use the task context as the primary instruction for what this draft must actually do. If the task says warm-up comment, write a comment. If it says cold email, write an email. If it says soft-touch follow-up, do not write a DM. Adapt wording and framing to the product category, audience age, and brand tone from the project context when available. Return a sharp, usable draft with concise notes about why it fits.";

        $result = $this->ai->structured(
            $systemPrompt,
            $userPrompt,
            'submit_personalized_message',
            'Return the personalized outreach draft and supporting analysis.',
            $schema,
            0.5,
        );

        $result['messageType'] = (string) ($result['messageType'] ?? $messageType);

        return $result;
    }

    private function defaultMessageType(string $platform): string
    {
        return match (strtolower($platform)) {
            'email' => 'email',
            'reddit' => 'comment',
            'quora' => 'answer',
            default => 'dm',
        };
    }
}
