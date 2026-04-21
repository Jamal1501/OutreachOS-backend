<?php

namespace App\Services;

class AiPersonalizationService
{
    private const FORBIDDEN_OUTREACH_PHRASES = [
        'your post stood out',
        'your content stood out',
        'your profile stood out',
        'i came across your profile',
        'came across your profile',
        'i stumbled upon your profile',
        'love your content',
        'love your vibe',
        'would love to collaborate',
        'your x post stood out',
        'your recent post stood out',
    ];

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
        $evidencePack = $this->buildEvidencePack($creator, $projectContext, $taskContext, $templateContext, $replyContext);

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
                        'selectedEvidenceHook' => ['type' => 'string'],
                        'evidenceQuality' => ['type' => 'string'],
                        'fallbackReason' => ['type' => 'string'],
                        'toneUsed' => ['type' => 'string'],
                        'fitScore' => ['type' => 'number'],
                    ],
                    'required' => [
                        'creatorSummary',
                        'styleSignals',
                        'audienceSignals',
                        'proofPoints',
                        'personalizationHooks',
                        'risksToAvoid',
                        'recommendedAngle',
                        'selectedEvidenceHook',
                        'evidenceQuality',
                        'fallbackReason',
                        'toneUsed',
                        'fitScore',
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['personalizedMessage', 'personalizationNotes', 'fitScore', 'confidenceScore', 'toneUsed', 'messageType', 'analysis'],
            'additionalProperties' => false,
        ];

        $systemPrompt = <<<'PROMPT'
You are SocialCore's creator-outreach copywriter.

Your job is not to decorate a template. Your job is to write a usable, specific outreach draft from evidence.

Non-negotiable rules:
- Never invent a creator detail, post topic, product fit, audience type, location, or relationship.
- Do not mention a specific post unless a caption/title/topic/URL is present in the provided evidence.
- The first line must be based on one concrete observable signal when available: bio phrase, repeated content theme, caption detail, audience clue, niche fit, or reply context.
- If evidence is thin, be honest and write a clean relevance-based opener. Do not fake observation.
- Avoid mass-DM language, fake intimacy, hype, begging, and generic compliments.
- Keep the offer concrete. Make the next step small.
- Use the base template only as strategic intent. Rewrite the actual message from scratch.
- Match the brand/product context. A teen beauty brand, a premium furniture store, and a B2B SaaS must not sound the same.
- Respect the task context over everything else. If the task asks for a comment, write a comment. If it asks for a follow-up action that cannot be a DM, do not write a DM.

Forbidden phrases and patterns:
- "your post stood out"
- "your content stood out"
- "your profile stood out"
- "I came across your profile"
- "I stumbled upon your profile"
- "love your content"
- "love your vibe"
- "would love to collaborate"
- "your recent post stood out"
- any version of "your X post stood out"

Length guidance:
- Instagram/TikTok cold DM: 280-520 characters, max 2 short paragraphs.
- Follow-up DM: 180-420 characters.
- Warm-up comment: 80-180 characters, no sales pitch.
- Email: concise subject-style first line is fine; 90-160 words max.

Output must be ready to send. No placeholders. No brackets. No fake metrics. No explanation inside the message.
PROMPT;

        $userPrompt = $this->buildUserPrompt(
            projectContext: $projectContext,
            stage: $stage,
            messageType: $messageType,
            taskContext: $taskContext,
            creator: $creator,
            evidencePack: $evidencePack,
            templateContext: $templateContext,
            template: $template,
            previousMessage: $previousMessage,
            replyContext: $replyContext,
            conversationGoal: $conversationGoal,
        );

        $result = $this->ai->structured(
            $systemPrompt,
            $userPrompt,
            'submit_personalized_message',
            'Return the personalized outreach draft and supporting analysis.',
            $schema,
            0.45,
        );

        $result['messageType'] = (string) ($result['messageType'] ?? $messageType);
        $result = $this->guardAgainstCheapOutreach($result);

        return $result;
    }

    private function buildUserPrompt(
        string $projectContext,
        string $stage,
        string $messageType,
        array $taskContext,
        array $creator,
        array $evidencePack,
        array $templateContext,
        string $template,
        string $previousMessage,
        array $replyContext,
        string $conversationGoal,
    ): string {
        $sections = [];

        $sections[] = "PROJECT / BRAND CONTEXT\n" . ($projectContext !== '' ? $projectContext : 'No project context supplied. Keep the message neutral, professional, and low-assumption.');
        $sections[] = "OUTREACH STAGE\n" . ($stage !== '' ? $stage : 'unspecified');
        $sections[] = "MESSAGE TYPE\n" . $messageType;

        if (!empty($taskContext)) {
            $sections[] = "TASK CONTEXT — this overrides the template if there is conflict\n" . $this->json($taskContext);
        }

        $sections[] = "CREATOR EVIDENCE PACK — use this before raw profile data\n" . $this->json($evidencePack);
        $sections[] = "RAW CREATOR PROFILE — for backup only; do not invent beyond it\n" . $this->json($creator);
        $sections[] = "TEMPLATE CONTEXT — strategic angle, not wording to copy\n" . $this->json($templateContext);
        $sections[] = "BASE TEMPLATE — treat as intent; rewrite from scratch\n" . ($template !== '' ? $template : 'No template supplied.');

        if ($previousMessage !== '') {
            $sections[] = "PREVIOUS MESSAGE — avoid repeating this\n" . $previousMessage;
        }

        if (!empty($replyContext)) {
            $sections[] = "REPLY CONTEXT — respond to this if present\n" . $this->json($replyContext);
        }

        if ($conversationGoal !== '') {
            $sections[] = "CONVERSATION GOAL\n" . $conversationGoal;
        }

        $sections[] = <<<'INSTRUCTIONS'
WRITE THE DRAFT
1. Pick exactly one strongest evidence hook. Put it in analysis.selectedEvidenceHook.
2. Write the message using that hook naturally, without sounding like a scraped-data robot.
3. If no strong hook exists, say so in analysis.fallbackReason and write a clean, honest relevance-based opener.
4. Do not use any forbidden phrase.
5. Do not say a post stood out. Ever.
6. Do not over-compliment. One specific observation beats three generic compliments.
7. Make the CTA small: quick yes/no, permission to send details, or a simple question.
8. Return only the structured payload.
INSTRUCTIONS;

        return implode("\n\n---\n\n", $sections);
    }

    private function buildEvidencePack(array $creator, string $projectContext, array $taskContext, array $templateContext, array $replyContext): array
    {
        $bio = $this->textFromKeys($creator, ['bio', 'biography', 'description', 'about', 'profileBio']);
        $niche = $this->textFromKeys($creator, ['niche', 'category', 'vertical', 'segment']);
        $handle = $this->textFromKeys($creator, ['handle', 'username', 'profileName']);
        $fullName = $this->textFromKeys($creator, ['fullName', 'name', 'displayName']);
        $platform = $this->textFromKeys($creator, ['platform', 'source']);
        $posts = $this->extractRecentPosts($creator);

        $bioSignals = $this->extractSignalsFromText($bio, 6);
        $postSignals = [];
        foreach ($posts as $post) {
            foreach ($this->extractSignalsFromText((string) ($post['caption'] ?? ''), 4) as $signal) {
                $postSignals[] = $signal;
            }
        }

        $repeatedThemes = $this->deriveRepeatedThemes(array_merge($bioSignals, $postSignals));
        $hasSpecificEvidence = trim($bio) !== '' || count($posts) > 0 || !empty($replyContext);

        return [
            'handle' => $handle,
            'fullName' => $fullName,
            'platform' => $platform,
            'niche' => $niche,
            'bio' => $bio,
            'bioSignals' => array_values(array_unique($bioSignals)),
            'recentPosts' => $posts,
            'postSignals' => array_values(array_slice(array_unique($postSignals), 0, 10)),
            'repeatedThemes' => $repeatedThemes,
            'availableMetrics' => [
                'followers' => $creator['followers'] ?? $creator['followerCount'] ?? null,
                'engagementRate' => $creator['engagementRate'] ?? $creator['engRate'] ?? null,
                'views' => $creator['views'] ?? $creator['sourceViews'] ?? null,
                'likes' => $creator['likes'] ?? $creator['sourceLikes'] ?? null,
            ],
            'brandContextAvailable' => $projectContext !== '',
            'taskContextAvailable' => !empty($taskContext),
            'templateContextAvailable' => !empty($templateContext),
            'replyContextAvailable' => !empty($replyContext),
            'evidenceStrength' => $hasSpecificEvidence ? 'specific_evidence_available' : 'thin_profile_data',
            'safeHookInstruction' => $hasSpecificEvidence
                ? 'Use one specific bio, caption, theme, or reply-context signal. Do not use vague praise.'
                : 'No specific creator evidence is available. Use a relevance-based opener without claiming you noticed a specific post or detail.',
        ];
    }

    private function extractRecentPosts(array $creator): array
    {
        $candidateKeys = ['recentPosts', 'posts', 'latestPosts', 'topPosts', 'contentSamples'];
        $posts = [];

        foreach ($candidateKeys as $key) {
            if (isset($creator[$key]) && is_array($creator[$key])) {
                $posts = $creator[$key];
                break;
            }
        }

        if (empty($posts)) {
            foreach (['caption', 'latestCaption', 'sourceCaption', 'postCaption'] as $captionKey) {
                if (!empty($creator[$captionKey]) && is_string($creator[$captionKey])) {
                    $posts[] = ['caption' => $creator[$captionKey]];
                    break;
                }
            }
        }

        $normalized = [];
        foreach (array_slice($posts, 0, 5) as $post) {
            if (!is_array($post)) {
                continue;
            }

            $caption = $this->textFromKeys($post, ['caption', 'text', 'title', 'description']);
            $url = $this->textFromKeys($post, ['url', 'postUrl', 'permalink']);
            $timestamp = $this->textFromKeys($post, ['timestamp', 'createdAt', 'postedAt', 'date']);

            if ($caption === '' && $url === '') {
                continue;
            }

            $normalized[] = [
                'caption' => $caption !== '' ? $this->limitText($caption, 320) : '',
                'url' => $url,
                'timestamp' => $timestamp,
                'metrics' => [
                    'likes' => $post['likes'] ?? $post['likeCount'] ?? null,
                    'comments' => $post['comments'] ?? $post['commentCount'] ?? null,
                    'views' => $post['views'] ?? $post['viewCount'] ?? null,
                ],
            ];
        }

        return $normalized;
    }

    private function extractSignalsFromText(string $text, int $limit): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?: '');
        if ($text === '') {
            return [];
        }

        preg_match_all('/#([\p{L}\p{N}_]{3,40})/u', $text, $hashtagMatches);
        preg_match_all('/@([A-Za-z0-9_.]{3,40})/u', $text, $mentionMatches);

        $signals = [];
        foreach (array_slice($hashtagMatches[1] ?? [], 0, $limit) as $tag) {
            $signals[] = '#' . $tag;
        }
        foreach (array_slice($mentionMatches[1] ?? [], 0, max(0, $limit - count($signals))) as $mention) {
            $signals[] = '@' . $mention;
        }

        $clean = trim(preg_replace('/https?:\/\/\S+|[#@][\p{L}\p{N}_.]+/u', '', $text) ?: '');
        $sentences = preg_split('/(?<=[.!?])\s+/u', $clean) ?: [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence) < 18 || mb_strlen($sentence) > 180) {
                continue;
            }
            $signals[] = $this->limitText($sentence, 160);
            if (count($signals) >= $limit) {
                break;
            }
        }

        if (empty($signals) && $clean !== '') {
            $signals[] = $this->limitText($clean, 160);
        }

        return array_values(array_slice(array_unique($signals), 0, $limit));
    }

    private function deriveRepeatedThemes(array $signals): array
    {
        $themes = [];
        $stopWords = ['with', 'from', 'that', 'this', 'your', 'about', 'have', 'into', 'and', 'the', 'for', 'you', 'are', 'but', 'not', 'all'];

        foreach ($signals as $signal) {
            $lower = mb_strtolower((string) $signal);
            preg_match_all('/[\p{L}\p{N}]{4,}/u', $lower, $matches);
            foreach ($matches[0] ?? [] as $word) {
                if (in_array($word, $stopWords, true)) {
                    continue;
                }
                $themes[$word] = ($themes[$word] ?? 0) + 1;
            }
        }

        arsort($themes);

        return array_values(array_slice(array_keys(array_filter($themes, fn ($count) => $count > 1)), 0, 6));
    }

    private function guardAgainstCheapOutreach(array $result): array
    {
        $message = (string) ($result['personalizedMessage'] ?? '');
        $lower = mb_strtolower($message);
        $violations = [];

        foreach (self::FORBIDDEN_OUTREACH_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                $violations[] = $phrase;
            }
        }

        if (preg_match('/your\s+.{0,80}\s+post\s+stood\s+out/i', $message)) {
            $violations[] = 'your X post stood out';
        }

        if (!empty($violations)) {
            $result['personalizationNotes'] = trim((string) ($result['personalizationNotes'] ?? '') . ' Quality warning: draft used banned generic outreach wording (' . implode(', ', array_unique($violations)) . '). Regenerate with stronger evidence.');
            $result['confidenceScore'] = min((float) ($result['confidenceScore'] ?? 0.5), 0.35);
            $result['analysis']['risksToAvoid'][] = 'Regenerate: banned generic outreach wording detected.';
            $result['analysis']['fallbackReason'] = (string) ($result['analysis']['fallbackReason'] ?? 'Banned generic outreach wording detected.');
        }

        return $result;
    }

    private function textFromKeys(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                $value = trim((string) $source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function limitText(string $text, int $maxLength): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?: '');
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
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
