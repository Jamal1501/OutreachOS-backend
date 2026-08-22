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
        'love what you are doing',
        'love what you’re doing',
        'big fan of your content',
        'would love to collaborate',
        'your x post stood out',
        'your recent post stood out',
    ];

    public function __construct(private AiGatewayService $ai) {}

    public function personalize(array $payload): array
    {
        $creator = (array) ($payload['creator'] ?? []);
        $template = trim((string) ($payload['template'] ?? ''));
        $stage = trim((string) ($payload['stage'] ?? ''));
        $projectContext = trim((string) ($payload['projectContext'] ?? ''));
        $outreachContext = $this->normalizeOutreachContext((array) ($payload['outreachContext'] ?? []));
        $templateContext = (array) ($payload['templateContext'] ?? []);
        $replyContext = (array) ($payload['replyContext'] ?? []);
        $taskContext = (array) ($payload['taskContext'] ?? []);
        $generationMode = trim((string) ($payload['generationMode'] ?? $taskContext['generationMode'] ?? 'fresh_draft')) ?: 'fresh_draft';
        $tonePreference = $this->normalizeTonePreference((string) ($payload['tonePreference'] ?? $taskContext['tonePreference'] ?? ''));
        $previousMessage = trim((string) ($payload['previousMessage'] ?? ''));
        $conversationGoal = trim((string) ($payload['conversationGoal'] ?? ''));
        $outputLanguage = strtolower(trim((string) ($payload['outputLanguage'] ?? 'en'))) === 'de' ? 'de' : 'en';
        $messageType = $this->normalizeMessageType(
            trim((string) ($payload['messageType'] ?? '')) ?: $this->defaultMessageType((string) ($creator['platform'] ?? 'instagram'))
        );
        $evidencePack = $this->buildEvidencePack($creator, $projectContext, $taskContext, $templateContext, $replyContext);

        $schema = [
            'type' => 'object',
            'properties' => [
                'personalizedMessage' => ['type' => 'string'],
                'emailSubject' => ['type' => 'string'],
                'personalizationNotes' => ['type' => 'string'],
                'creativeAngle' => ['type' => 'string'],
                'contentIdea' => ['type' => 'string'],
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
                        'creatorContentIdea' => ['type' => 'string'],
                        'contentMechanic' => ['type' => 'string'],
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
                        'creatorContentIdea',
                        'contentMechanic',
                        'selectedEvidenceHook',
                        'evidenceQuality',
                        'fallbackReason',
                        'toneUsed',
                        'fitScore',
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['personalizedMessage', 'emailSubject', 'personalizationNotes', 'creativeAngle', 'contentIdea', 'fitScore', 'confidenceScore', 'toneUsed', 'messageType', 'analysis'],
            'additionalProperties' => false,
        ];

        $systemPrompt = <<<'PROMPT'
You are SocialCore's creator-outreach operator.

Write like a capable human who understands the offer, the channel, and the creator context. Do not write like a generic AI copywriter.

Primary job:
- Earn the next positive reply with a clear, credible opportunity that fits the selected channel and relationship stage.
- A creative idea is optional in first outreach. Use at most one short direction when it makes the opportunity easier to understand. Never turn a cold message into a campaign brief.
- This must work for any niche. Never hardcode gift, family, fitness, beauty, food, or software angles unless the evidence supports that niche.
- The message should make the creator understand who is writing, why they were selected, what kind of opportunity this is, and the easiest next step.

Core rules:
- Never invent creator details, post topics, audience demographics, location, relationship status, needs, pain points, metrics, or brand features.
- Do not mention a specific post unless a caption, title, topic, URL, or reply is actually provided.
- Do not force personalization. Weak or thin evidence should produce a clean, honest relevance-based message, not a fake observation.
- In first outreach, strong creator evidence should be visible in the message. When a real recent post caption, title, or topic is supplied, reference one concrete topic naturally in the first two sentences so the creator understands why they were selected.
- Prefer wording such as "Your post about X made me think of..." or "The way you explain X would suit...". Do not make a generic discovery claim without naming the supported topic.
- Treat templates as strategic angle memory only. Never copy template wording unless the user clearly wrote a draft and the mode is rewrite_existing_draft.
- If GENERATION MODE is rewrite_existing_draft, the user-written draft is explicit direction. Preserve concrete offer details, incentives, product facts, constraints, and requested CTA unless they are unsafe or directly conflict with task/brand context.
- Use creator location only as quiet relevance when confidence is high. Never open with it. Never imply audience location.
- Match the chosen tonePreference. Tone changes sentence shape and word choice; it does not permit fake compliments.
- Respect the task context over everything else. If the task asks for a comment, write a comment. If it asks for email, write email.
- For cold outreach, prefer one interest CTA or permission to send details. Do not request a meeting by default.

Human style rules:
- Use plain language.
- Prefer one concrete reason over multiple fluffy compliments.
- Avoid marketing-theatre words like "synergy", "amazing opportunity", "perfect fit", "collaboration journey", and "aligned values" unless the brand context truly requires them.
- Do not sound overexcited. Calm beats desperate.
- Do not start every message with "Hey @handle" if the channel/context makes that awkward. A simple name or handle is enough.

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
- "love what you're doing"
- "big fan of your content"
- "would love to collaborate"
- "your recent post stood out"
- any version of "your X post stood out"

Length guidance:
- Instagram/TikTok cold DM: 180-350 characters, 2-4 short lines, max 2 short paragraphs.
- Follow-up DM: 160-340 characters.
- Warm-up comment: 60-160 characters, no sales pitch.
- Ultra short tone: keep DM under 300 characters unless email.
- Cold email: return a short subject in `emailSubject` and a 70-110 word body in `personalizedMessage`. Follow-up email must be shorter than the first message. Do not put `Subject:` inside the message body.
- DM, comment, post, and answer: `emailSubject` must be an empty string. Never include a subject line or email formatting.
- Email sign-off must use TASK CONTEXT senderSignature when provided.
- Never end with placeholders like "your name", "[Name]", "(your name)", "company name", or "[Company]".
- If no senderSignature is provided but a brand/company is clear, sign with the company name only. If neither is available, use a plain sign-off without a fake name.
- Never use the em dash character. Use commas, periods, colons, or short sentences instead.

Output must be ready to send. No placeholders. No brackets. No fake metrics. No explanation inside the message.
PROMPT;

        $userPrompt = $this->buildUserPrompt(
            projectContext: $projectContext,
            outreachContext: $outreachContext,
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
            generationMode: $generationMode,
            tonePreference: $tonePreference,
            outputLanguage: $outputLanguage,
        );

        $result = $this->ai->structured(
            $systemPrompt,
            $userPrompt,
            'submit_personalized_message',
            'Return the personalized outreach draft and supporting analysis.',
            $schema,
            0.45,
        );

        $result = $this->sanitizeGeneratedPayload($result);
        $returnedMessageType = $this->normalizeMessageType((string) ($result['messageType'] ?? ''));

        $violations = array_values(array_unique(array_merge(
            $this->detectCheapOutreachViolations((string) ($result['personalizedMessage'] ?? '')),
            $this->detectChannelContractViolations($result, $messageType, $returnedMessageType),
            $this->detectOfferContractViolations((string) ($result['personalizedMessage'] ?? ''), $outreachContext, $outputLanguage),
        )));
        if (! empty($violations)) {
            $repairPrompt = $userPrompt
                ."\n\n---\n\nQUALITY REPAIR REQUIRED\n"
                .'The first draft violated these requirements: '.implode(', ', $violations).".\n"
                .'Rewrite from scratch for MESSAGE TYPE '.$messageType.'. The selected message type is mandatory. Avoid the mirrored-observation opener, use evidence only when it sounds natural, do not reuse any banned phrase, and never use the em dash character.';

            $repaired = $this->ai->structured(
                $systemPrompt,
                $repairPrompt,
                'submit_personalized_message',
                'Return the repaired personalized outreach draft and supporting analysis.',
                $schema,
                0.35,
            );

            $repaired = $this->sanitizeGeneratedPayload($repaired);
            $repaired['personalizationNotes'] = trim((string) ($repaired['personalizationNotes'] ?? '').' Auto-repaired first draft after banned generic wording was detected.');

            return $this->guardAgainstCheapOutreach($this->enforceChannelContract($repaired, $messageType, $taskContext));
        }

        return $this->guardAgainstCheapOutreach($this->enforceChannelContract($result, $messageType, $taskContext));
    }

    private function buildUserPrompt(
        string $projectContext,
        array $outreachContext,
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
        string $generationMode,
        string $tonePreference,
        string $outputLanguage,
    ): string {
        $sections = [];

        $sections[] = "PROJECT / BRAND CONTEXT\n".($projectContext !== '' ? $projectContext : 'No project context supplied. Keep the message neutral, professional, and low-assumption.');
        $sections[] = "STRUCTURED OFFER CONTEXT - factual source of truth\n".$this->json($outreachContext);
        $sections[] = "OUTREACH STAGE\n".($stage !== '' ? $stage : 'unspecified');
        $sections[] = "MESSAGE TYPE\n".$messageType;
        $sections[] = "CHANNEL CONTRACT - mandatory\n".$this->channelContractInstruction($messageType);
        $sections[] = "GENERATION MODE\n".($generationMode !== '' ? $generationMode : 'fresh_draft');
        $sections[] = "TONE PREFERENCE\n".$tonePreference;
        $sections[] = "OUTPUT LANGUAGE - mandatory\n".($outputLanguage === 'de'
            ? 'German. Write the subject, message, CTA wording, and all customer-visible analysis fields in natural German. Do not mix in English except unavoidable brand or product names.'
            : 'English. Write the subject, message, CTA wording, and all customer-visible analysis fields in natural English.');

        if (! empty($taskContext)) {
            $sections[] = "TASK CONTEXT - this overrides the template if there is conflict\n".$this->json($taskContext);
        }

        $sections[] = "CREATOR EVIDENCE PACK - use this before raw profile data\n".$this->json($evidencePack);
        $sections[] = "RAW CREATOR PROFILE - for backup only; do not invent beyond it\n".$this->json($creator);
        $sections[] = "TEMPLATE CONTEXT - learned angle memory, not final copy\n".$this->json($templateContext);
        if ($template !== '' && $generationMode === 'rewrite_existing_draft') {
            $sections[] = "USER-WRITTEN DRAFT - mandatory message intent, improve wording but preserve concrete facts\n".$template;
        } else {
            $sections[] = "HIDDEN TEMPLATE - learned angle memory, ignore wording when mode is fresh_draft\n".($template !== '' ? $template : 'No template supplied. Generate a fresh draft from evidence, brand context, task context, and tone preference.');
        }

        if ($previousMessage !== '') {
            $sections[] = "PREVIOUS MESSAGE - avoid repeating this\n".$previousMessage;
        }

        if (! empty($replyContext)) {
            $sections[] = "REPLY CONTEXT - respond to this if present\n".$this->json($replyContext);
        }

        if ($conversationGoal !== '') {
            $sections[] = "CONVERSATION GOAL\n".$conversationGoal;
        }

        $sections[] = <<<'INSTRUCTIONS'
MESSAGE STRATEGY
Before writing, determine the stage, channel, sender identity, offer type, compensation disclosure, deliverable certainty, strongest supported creator evidence, and one CTA.
- STRUCTURED OFFER CONTEXT is the factual source of truth for the commercial opportunity.
- If senderType is agency and showClientName is true, identify both the agency and client naturally. If false, do not reveal clientName.
- Mention compensation in first outreach when showBudget is true. Respect compensationMode: fixed, range, variable, creator_rates, or none. Never invent a number.
- compensationMode is authoritative. For creator_rates, ask for the creator's rates and never mention a budget number. For variable, say the opportunity is paid without inventing or reusing an amount. For none, do not imply cash payment.
- Defined deliverables may be stated clearly. Flexible deliverables must sound negotiable. Open deliverables should invite the creator's recommendation rather than inventing a format.
- For cold outreach, optimize for a positive reply. Use one low-friction interest CTA or ask permission to send details. Do not ask for a meeting unless task context explicitly requires it.
- For cold outreach with a non-empty recentPosts caption or title, use one specific post topic in the sendable message, normally in sentence one or two. The reference should explain why this creator is relevant, not act as empty praise.
- If there is no reliable post evidence, use a supported bio or niche detail. If all creator evidence is thin, use honest offer relevance and never fabricate familiarity.
- Follow-ups must continue from the previous message, add at most one useful detail, and be shorter. Never rewrite the whole pitch.

OPTIONAL CREATIVE DIRECTION
First outreach does not require a content concept. Use at most one short, creator-native direction only when it makes the offer more compelling and the evidence supports it.
Use any fuller idea only in the analysis fields. Do not crowd the sendable message with a mini brief.

The content idea must be niche-flexible:
- For apparel, think fit test, routine, comfort test, styling, before/after, packing, workout, daily wear, comparison, or challenge.
- For beauty, think routine, first impression, wear test, transformation, ingredient education, or problem/solution demo.
- For food, think recipe, taste test, family reaction, hosting moment, grocery routine, or challenge.
- For software/services, think workflow, before/after, teardown, template, time-saving test, mistake fix, or mini tutorial.
- For gifts, think reaction, reveal, memory game, challenge, personalization story, or recipient moment.
These are examples only. Pick the mechanic that fits the actual brand and creator evidence.

LOCATION USE
- Use creatorLocation only when usableForDraft is true.
- Never say "I saw you're based in..." or similar scraped-sounding lines.
- Never imply audience location.
- Location may shape the angle quietly, but it should almost never be the opener.

TONE USE
- warm_direct: human, clear, polite, low fluff.
- casual: relaxed DM language, still competent.
- premium: calm, polished, confident, no hype.
- playful: light and warm, but not childish or cringe.
- professional: concise business wording.
- ultra_short: smallest useful version.

WRITE THE DRAFT
1. Decide whether a creator-specific hook is actually worth using. If not, leave analysis.selectedEvidenceHook empty or say "none".
2. Invent one creator-native content idea. It should be specific enough that the creator can imagine filming or posting it.
3. Put the strategic angle in analysis.recommendedAngle, the post/video idea in analysis.creatorContentIdea and contentIdea, and the content format/mechanic in analysis.contentMechanic and creativeAngle.
4. Lead with the idea or the offer relevance. Do not lead with a fake compliment or scraped observation.
5. Use creator evidence only if it earns its place.
6. If no strong creator evidence exists, write a clean relevance-based idea using brand context and likely vertical only. Say why in analysis.fallbackReason.
7. Do not use any forbidden phrase.
8. Do not say a post stood out. Ever.
9. Do not use the mirror formula: "I noticed/saw/came across X, so I thought...".
10. Do not over-compliment. One useful content idea beats three generic compliments.
11. Make the CTA small: quick yes/no, permission to send details, or a simple question.
12. Never use the em dash character.
13. If USER-WRITTEN DRAFT is present, build around its offer and intent. Do not drop concrete user-provided details like "free gift", discount, budget, timeline, product, deliverable, or CTA.
14. Return only the structured payload.
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
        $hasPostEvidence = count($posts) > 0;
        $hasSpecificEvidence = trim($bio) !== '' || $hasPostEvidence || ! empty($replyContext);

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
            'creatorLocation' => $this->buildCreatorLocationContext($creator),
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
                    'creatorNativeContentIdea' => 'Turn the offer into a post, reel, story, video, challenge, test, routine, tutorial, reveal, comparison, or audience interaction the creator could naturally publish.',
                ],
                'avoid' => 'Do not invent market research, audience demographics, pain points, product features, or creator needs. Do not use a generic collaboration pitch when a content idea can be created.',
            ],
            'activationIdeaGuidance' => [
                'goal' => "Outreach should trigger the creator's creativity, not merely ask for a collaboration.",
                'messageFormula' => 'specific content idea + why the product fits + tiny next step',
                'examplesByCategory' => [
                    'apparel' => 'fit check, comfort test, styling, workout/day routine, comparison, packing list, wear test',
                    'beauty' => 'routine, first impression, wear test, before/after, ingredient explainer, transformation',
                    'food' => 'taste test, recipe, family reaction, hosting moment, challenge, grocery routine',
                    'softwareOrService' => 'workflow, teardown, before/after, template, mistake fix, result-based demo, mini tutorial',
                    'giftOrPersonalizedProduct' => 'gift reveal, reaction, memory challenge, personalization story, recipient moment',
                ],
                'hardRule' => 'Examples are not defaults. Pick only what fits the actual brand and creator evidence.',
            ],
            'brandContextAvailable' => $projectContext !== '',
            'taskContextAvailable' => ! empty($taskContext),
            'templateContextAvailable' => ! empty($templateContext),
            'replyContextAvailable' => ! empty($replyContext),
            'evidenceStrength' => $hasSpecificEvidence ? 'specific_evidence_available' : 'thin_profile_data',
            'firstOutreachEvidenceInstruction' => $hasPostEvidence
                ? 'A real recent post is available. In first outreach, reference one supported post topic naturally in the first two sentences so the creator can see why they were chosen.'
                : (trim($bio) !== ''
                    ? 'No usable recent post is available. A supported bio or niche detail may be used naturally; do not imply that a specific post was reviewed.'
                    : 'No reliable creator-specific evidence is available. Do not fabricate a post reference or claim prior familiarity.'),
            'safeHookInstruction' => $hasSpecificEvidence
                ? ($hasPostEvidence
                    ? 'Use one concrete recent-post topic in first outreach. Connect it directly to the opportunity instead of adding a generic compliment.'
                    : 'Use the supported bio or niche evidence only when it creates useful offer relevance.')
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
                if (! empty($creator[$captionKey]) && is_string($creator[$captionKey])) {
                    $posts[] = ['caption' => $creator[$captionKey]];
                    break;
                }
            }
        }

        $normalized = [];
        foreach (array_slice($posts, 0, 5) as $post) {
            if (! is_array($post)) {
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
            $signals[] = '#'.$tag;
        }
        foreach (array_slice($mentionMatches[1] ?? [], 0, max(0, $limit - count($signals))) as $mention) {
            $signals[] = '@'.$mention;
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

    private function buildCreatorLocationContext(array $creator): array
    {
        $country = $this->textFromKeys($creator, ['country', 'creatorCountry', 'locationCountry']);
        $city = $this->textFromKeys($creator, ['city', 'creatorCity', 'locationCity']);
        $confidence = $this->normalizeLocationConfidence($creator['locationConfidence'] ?? $creator['location_confidence'] ?? null);
        $sources = $this->normalizeStringList($creator['locationSources'] ?? $creator['location_sources'] ?? []);
        $hasLocation = $country !== '' || $city !== '';
        $usableForDraft = $hasLocation && $confidence >= 0.75;

        return [
            'country' => $country,
            'city' => $city,
            'confidence' => $hasLocation ? $confidence : null,
            'sources' => $sources,
            'usableForDraft' => $usableForDraft,
            'usageRule' => 'Estimated creator location only. Use as quiet relevance only when usableForDraft is true. Never imply audience location and never open with scraped-sounding location wording.',
        ];
    }

    private function normalizeLocationConfidence(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $number = (float) $value;
        if ($number > 1) {
            $number = $number / 100;
        }

        return max(0.0, min(1.0, $number));
    }

    private function normalizeStringList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $items = is_array($decoded) ? $decoded : preg_split('/[\n,]+/u', $value);
        } else {
            $items = [];
        }

        return array_values(array_slice(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $items ?: []
        )), 0, 8));
    }

    private function normalizeTonePreference(string $value): string
    {
        $normalized = preg_replace('/[\s-]+/u', '_', mb_strtolower(trim($value))) ?: '';
        $allowed = ['warm_direct', 'casual', 'premium', 'playful', 'professional', 'ultra_short'];

        return in_array($normalized, $allowed, true) ? $normalized : 'warm_direct';
    }

    private function normalizeOutreachContext(array $context): array
    {
        $mode = strtolower(trim((string) ($context['compensationMode'] ?? 'variable')));
        if (! in_array($mode, ['fixed', 'range', 'variable', 'creator_rates', 'none'], true)) {
            $mode = 'variable';
        }
        $context['compensationMode'] = $mode;

        if ($mode !== 'fixed') {
            unset($context['budgetFixed']);
        }
        if ($mode !== 'range') {
            unset($context['budgetMin'], $context['budgetMax']);
        }
        if (($context['showBudget'] ?? true) === false) {
            unset($context['budgetFixed'], $context['budgetMin'], $context['budgetMax']);
        }

        return $context;
    }

    private function normalizeMessageType(string $value): string
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['dm', 'post', 'comment', 'answer', 'email'], true)
            ? $normalized
            : 'dm';
    }

    private function channelContractInstruction(string $messageType): string
    {
        return match ($messageType) {
            'email' => 'Write an email only. Return a non-empty emailSubject separately. personalizedMessage must contain the email body only, use 70-110 words for first outreach, and read like an email rather than a social DM.',
            'comment' => 'Write one short public social comment only. emailSubject must be empty. Do not use a greeting, subject line, email sign-off, or private-message format.',
            'answer' => 'Write a direct reply to the creator only. emailSubject must be empty. Continue the conversation naturally and do not use a subject line or cold-email format.',
            'post' => 'Write social post copy only. emailSubject must be empty. Do not use email or direct-message formatting.',
            default => 'Write a social-platform DM only. emailSubject must be empty. Do not use a subject line, formal email structure, or email sign-off.',
        };
    }

    private function detectChannelContractViolations(array $result, string $requestedMessageType, string $returnedMessageType): array
    {
        $message = trim((string) ($result['personalizedMessage'] ?? ''));
        $emailSubject = trim((string) ($result['emailSubject'] ?? ''));
        $violations = [];

        if ($returnedMessageType !== $requestedMessageType) {
            $violations[] = 'returned '.$returnedMessageType.' instead of selected '.$requestedMessageType;
        }

        if ($requestedMessageType === 'email') {
            $wordCount = str_word_count(strip_tags($message));
            if ($wordCount < 45) {
                $violations[] = 'email body is too short and reads like a DM';
            }
            if ($wordCount > 130) {
                $violations[] = 'email body is too long for first-touch creator outreach';
            }
        } else {
            if ($emailSubject !== '' || preg_match('/^\s*subject\s*:/i', $message)) {
                $violations[] = 'non-email draft contains an email subject';
            }
            if ($requestedMessageType === 'dm' && (mb_strlen($message) > 400 || str_word_count(strip_tags($message)) > 65)) {
                $violations[] = 'DM is formatted like a long email';
            }
        }

        return $violations;
    }

    private function enforceChannelContract(array $result, string $messageType, array $taskContext): array
    {
        $message = trim((string) ($result['personalizedMessage'] ?? ''));
        $subjectFromBody = '';

        if (preg_match('/^\s*subject\s*:\s*([^\r\n]+)(?:\r?\n)+/i', $message, $matches)) {
            $subjectFromBody = trim((string) ($matches[1] ?? ''));
            $message = trim((string) preg_replace('/^\s*subject\s*:\s*[^\r\n]+(?:\r?\n)+/i', '', $message, 1));
        }

        $result['messageType'] = $messageType;
        $result['personalizedMessage'] = $message;
        $result['emailSubject'] = $messageType === 'email'
            ? (trim((string) ($result['emailSubject'] ?? ''))
                ?: $subjectFromBody
                ?: trim((string) ($taskContext['emailSubject'] ?? ''))
                ?: 'Creator collaboration idea')
            : '';

        return $result;
    }

    private function sanitizeGeneratedPayload(array $result): array
    {
        foreach ($result as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->sanitizeGeneratedText($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitizeGeneratedPayload($value);
            }
        }

        return $result;
    }

    private function sanitizeGeneratedText(string $text): string
    {
        $text = str_replace('—', ' - ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?: $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?: $text;

        return trim($text);
    }

    private function guardAgainstCheapOutreach(array $result): array
    {
        $violations = $this->detectCheapOutreachViolations((string) ($result['personalizedMessage'] ?? ''));

        if (! empty($violations)) {
            $result['personalizationNotes'] = trim((string) ($result['personalizationNotes'] ?? '').' Quality warning: draft used banned generic outreach wording ('.implode(', ', array_unique($violations)).'). Regenerate with stronger evidence.');
            $result['confidenceScore'] = min((float) ($result['confidenceScore'] ?? 0.5), 0.35);

            if (! isset($result['analysis']) || ! is_array($result['analysis'])) {
                $result['analysis'] = [];
            }
            if (! isset($result['analysis']['risksToAvoid']) || ! is_array($result['analysis']['risksToAvoid'])) {
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

        if (str_contains($message, '—')) {
            $violations[] = 'em dash character';
        }

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

        if (preg_match('/\b(big\s+fan|love\s+what\s+you[’\']?re\s+doing|amazing\s+content|perfect\s+fit)\b/i', $message)) {
            $violations[] = 'fake compliment / hype phrasing';
        }

        return array_values(array_unique($violations));
    }

    private function detectOfferContractViolations(string $message, array $outreachContext, string $outputLanguage): array
    {
        if (($outreachContext['compensationMode'] ?? null) !== 'creator_rates') {
            return [];
        }

        $lower = mb_strtolower($message);
        $asksCreatorForRates = $outputLanguage === 'de'
            ? (bool) preg_match('/\b(deine|ihre|eure)\b.{0,35}\b(konditionen|honorar|honorare|preise|rate)\b/iu', $lower)
            : (bool) preg_match('/\b(your)\b.{0,25}\b(rate|rates|pricing)\b/iu', $lower);
        $senderClaimsToProvideRates = (bool) preg_match(
            '/\b(i|we)\s+(can|could|will|would|[’\']ll)\s+(send|share|provide)\b[^.!?\n]{0,80}\b(creator\s+)?rates?\b/iu',
            $lower,
        );

        $violations = [];
        if (! $asksCreatorForRates) {
            $violations[] = 'creator-rate mode must ask the creator to share their rates';
        }
        if ($senderClaimsToProvideRates) {
            $violations[] = 'sender incorrectly claims they will provide creator rates';
        }

        return $violations;
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

        return rtrim(mb_substr($text, 0, $maxLength - 1)).'…';
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
