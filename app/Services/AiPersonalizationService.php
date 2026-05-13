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
        'i came across your work',
        'came across your work',
        'i stumbled upon your profile',
        'i noticed your',
        'noticed your',
        'i noticed that you',
        'noticed that you',
        'i saw your',
        'saw your',
        'i saw that you',
        'saw that you',
        'looks like you',
        'your work caught my eye',
        'your content caught my eye',
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
- Do not force a mirrored observation into the first line. Only reference creator evidence when it creates a genuinely useful reason for the offer.
- If evidence is thin, skip observation entirely and write a clean relevance-based opener. Do not fake observation.
- Avoid mass-DM language, fake intimacy, hype, begging, generic compliments, and repetitive scraped-data mirror lines.
- Keep the offer concrete. Make the next step small.
- Use the base template only as strategic intent. Rewrite the actual message from scratch.
- Match the brand/product context. A teen beauty brand, a premium furniture store, and a B2B SaaS must not sound the same.
- Translate the brand story into the creator's specific vertical or lived use case. Do not repeat generic brand USPs when a more relevant benefit fits the creator.
- Prefer creator-specific product relevance over generic positioning. Example principle: if a skincare brand talks to fitness creators, emphasize post-workout skin needs, quick use, sweat/oil buildup, or simple ingredients only if those benefits exist in the brand context.
- Treat creator content, bio, hashtags, niche, and reply context as lightweight market research signals. Use them to choose the angle, not to invent audience facts.
- Make it easy to see how the product fits into the creator's daily life, content world, or audience problem.
- Prefer offer-led relevance over creator-mirroring. The message should not read like: "I noticed you post about X, so..." on every draft.
- Respect the task context over everything else. If the task asks for a comment, write a comment. If it asks for a follow-up action that cannot be a DM, do not write a DM.

Forbidden phrases and patterns:
- "your post stood out"
- "your content stood out"
- "your profile stood out"
- "I came across your profile"
- "I stumbled upon your profile"
- "I came across your work"
- "I noticed your..."
- "I noticed that you..."
- "I saw your..."
- "I saw that you..."
- "Looks like you..."
- "your work caught my eye"
- "your content caught my eye"
- "love your content"
- "love your vibe"
- "would love to collaborate"
- "your recent post stood out"
- any version of "your X post stood out"

Length guidance:
- Instagram/TikTok cold DM: 280-520 characters, max 2 short paragraphs.
- Follow-up DM: 180-420 characters.
- Warm-up comment: 80-180 characters, no sales pitch.
- Email: start with a short `Subject: ...` line, then a blank line, then a 90-160 word email body.
- Email sign-off must use TASK CONTEXT senderSignature when provided.
- Never end with placeholders like "your name", "[Name]", "(your name)", "company name", or "[Company]".
- If no senderSignature is provided but a brand/company is clear, sign with the company name only. If neither is available, use a plain sign-off without a fake name.

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

        $violations = $this->detectCheapOutreachViolations((string) ($result['personalizedMessage'] ?? ''));
        if (!empty($violations)) {
            $repairPrompt = $userPrompt
                . "\n\n---\n\nQUALITY REPAIR REQUIRED\n"
                . "The first draft used banned generic outreach wording: " . implode(', ', $violations) . ".\n"
                . "Rewrite from scratch. Avoid the mirrored-observation opener. Lead with creator-specific offer relevance, use evidence only when it sounds natural, and do not reuse any banned phrase.";

            $repaired = $this->ai->structured(
                $systemPrompt,
                $repairPrompt,
                'submit_personalized_message',
                'Return the repaired personalized outreach draft and supporting analysis.',
                $schema,
                0.35,
            );

            $repaired['messageType'] = (string) ($repaired['messageType'] ?? $messageType);
            $repaired['personalizationNotes'] = trim((string) ($repaired['personalizationNotes'] ?? '') . ' Auto-repaired first draft after banned generic wording was detected.');

            return $this->guardAgainstCheapOutreach($repaired);
        }

        return $this->guardAgainstCheapOutreach($result);
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
CREATOR-SPECIFIC BRAND ANGLE
Before writing, mentally do this mapping:
- What creator vertical/use case is visible from the evidence?
- Which brand benefit from PROJECT / BRAND CONTEXT is most relevant to that vertical/use case?
- How can the product fit into the creator's daily life, content world, or audience problem?
Use this mapping in analysis.recommendedAngle. Do not mention a benefit unless the brand context supports it.

WRITE THE DRAFT
1. Pick exactly one strongest evidence hook for analysis.selectedEvidenceHook, but do not force it into the first line.
2. Pick one creator-specific brand/use-case angle. Put it in analysis.recommendedAngle.
3. Lead with why the offer could make sense for this creator or audience. Use evidence only if it earns its place.
4. If no strong hook exists, say so in analysis.fallbackReason and write a clean, honest relevance-based opener with no fake observation.
5. Do not use any forbidden phrase.
6. Do not say a post stood out. Ever.
7. Do not use the mirror formula: "I noticed/saw/came across X, so I thought...".
8. Do not over-compliment. One useful relevance point beats three generic compliments.
9. Make the CTA small: quick yes/no, permission to send details, or a simple question.
10. Return only the structured payload.
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
            'brandFitGuidance' => [
                'goal' => 'Choose the brand/product angle that is most relevant to this creator vertical or use case. Do not default to the brand headline USP if another supported benefit fits better.',
                'requiredMapping' => [
                    'creatorVerticalOrUseCase' => 'Infer only from bio, niche, captions, hashtags, reply context, or task context.',
                    'brandBenefitToEmphasize' => 'Use only benefits found in project/brand/template/task context.',
                    'dailyLifeFit' => "Explain the product fit in a way that would feel natural inside the creator's content or routine.",
                ],
                'avoid' => 'Do not invent market research, audience demographics, pain points, product features, or creator needs.',
            ],
            'brandContextAvailable' => $projectContext !== '',
            'taskContextAvailable' => !empty($taskContext),
            'templateContextAvailable' => !empty($templateContext),
            'replyContextAvailable' => !empty($replyContext),
            'evidenceStrength' => $hasSpecificEvidence ? 'specific_evidence_available' : 'thin_profile_data',
            'safeHookInstruction' => $hasSpecificEvidence
                ? 'Evidence is available, but do not force it into a mirrored first line. Use it only when it creates useful offer relevance.'
                : 'No specific creator evidence is available. Use a relevance-based opener without claiming you noticed, saw, liked, or came across a specific detail.',
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
        $violations = $this->detectCheapOutreachViolations((string) ($result['personalizedMessage'] ?? ''));

        if (!empty($violations)) {
            $result['personalizationNotes'] = trim((string) ($result['personalizationNotes'] ?? '') . ' Quality warning: draft used banned generic outreach wording (' . implode(', ', array_unique($violations)) . '). Regenerate with stronger evidence.');
            $result['confidenceScore'] = min((float) ($result['confidenceScore'] ?? 0.5), 0.35);

            if (!isset($result['analysis']) || !is_array($result['analysis'])) {
                $result['analysis'] = [];
            }
            if (!isset($result['analysis']['risksToAvoid']) || !is_array($result['analysis']['risksToAvoid'])) {
                $result['analysis']['risksToAvoid'] = [];
            }

            $result['analysis']['risksToAvoid'][] = 'Regenerate: banned generic outreach wording detected.';
            $result['analysis']['fallbackReason'] = (string) ($result['analysis']['fallbackReason'] ?? 'Banned generic outreach wording detected.');
        }

        return $result;
    }

    private function detectCheapOutreachViolations(string $message): array
    {
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

        if (preg_match('/\b(i\s+)?(noticed|saw)\s+(that\s+)?you(r)?\b/i', $message)) {
            $violations[] = 'mirrored observation opener';
        }

        if (preg_match('/\b(i\s+)?came\s+across\s+your\s+(work|profile|content)\b/i', $message)) {
            $violations[] = 'came across your work/profile/content';
        }

        if (preg_match('/\byour\s+(work|content|profile)\s+caught\s+my\s+eye\b/i', $message)) {
            $violations[] = 'your work/content/profile caught my eye';
        }

        return array_values(array_unique($violations));
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
