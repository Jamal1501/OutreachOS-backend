<?php

namespace App\Services;

class AiDiscoveryBriefService
{
    public function __construct(private AiGatewayService $ai) {}

    public function parse(string $brief, array $context = []): array
    {
        $brief = trim($brief);
        if ($brief === '') {
            return $this->fallbackCriteria($brief, $context);
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'productOrOffer' => ['type' => 'string'],
                'audienceDescription' => ['type' => 'string'],
                'locationText' => ['type' => 'string'],
                'locationInferred' => ['type' => 'boolean'],
                'genderTarget' => ['type' => 'string'],
                'genderInferred' => ['type' => 'boolean'],
                'followerMin' => ['type' => 'integer'],
                'followerMax' => ['type' => 'integer'],
                'activeWithinDays' => ['type' => 'integer'],
                'primaryHashtag' => ['type' => 'string'],
                'optionalHashtags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'hashtags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'searchTerms' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'nicheKeywords' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'languageHints' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'mustHaveSignals' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'avoidSignals' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'recommendedDiscoveryLimit' => ['type' => 'integer'],
                'recommendedEnrichmentLimit' => ['type' => 'integer'],
                'notes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [
                'summary',
                'productOrOffer',
                'audienceDescription',
                'locationText',
                'locationInferred',
                'genderTarget',
                'genderInferred',
                'followerMin',
                'followerMax',
                'activeWithinDays',
                'primaryHashtag',
                'optionalHashtags',
                'hashtags',
                'searchTerms',
                'nicheKeywords',
                'languageHints',
                'mustHaveSignals',
                'avoidSignals',
                'recommendedDiscoveryLimit',
                'recommendedEnrichmentLimit',
                'notes',
            ],
            'additionalProperties' => false,
        ];

        $platform = strtolower(trim((string) ($context['platform'] ?? 'instagram')));
        $projectContext = trim((string) ($context['projectContext'] ?? ''));

        $systemPrompt = <<<'PROMPT'
You turn a messy creator-search brief into structured discovery criteria for an outreach tool.
Be commercially sharp, skeptical, and concrete.
Do not invent certainty.

Rules:
- Extract the real product, audience, tone, and creator niche.
- Build hashtag ideas that are PUBLIC-search friendly on the target platform.
- Mix 1 broad primary hashtag with 2-4 more creative adjacent hashtags based on use-case, identity, aesthetic, and subculture.
- Avoid generic trash unless the brief truly gives nothing better.
- Only set followerMin/followerMax when the brief or brand context implies a size band (nano, micro, mid-tier, macro, premium, luxury, celebrity, etc.).
- If no explicit size band exists, use 0/0.
- Return searchTerms that an operator could use manually.
- Return nicheKeywords that help downstream ranking distinguish strong fits from random creators.
- Must-have signals should be practical evidence, not fluff.
- Avoid-signals should catch obvious bad fits.
PROMPT;

        $userPrompt = "Platform: {$platform}\n"
            .($projectContext !== '' ? "Brand / project context:\n{$projectContext}\n\n" : '')
            ."User brief:\n{$brief}\n\n"
            .'Return structured discovery criteria for a creator discovery pipeline.'
            .'If activity recency is missing, use 30 days.'
            .'If gender or location is not explicit, only infer them when the wording strongly implies it.'
            .'Give one clear primary hashtag and up to four stronger optional hashtags, not just bland surface-level tags.';

        try {
            $result = $this->ai->structured(
                $systemPrompt,
                $userPrompt,
                'submit_discovery_brief',
                'Return structured discovery criteria for creator outreach.',
                $schema,
                0.35,
            );
        } catch (\Throwable $e) {
            return $this->fallbackCriteria($brief, $context, $e->getMessage());
        }

        return $this->normalizeCriteria($result, $brief, $context);
    }

    private function normalizeCriteria(array $criteria, string $brief, array $context, ?string $fallbackNote = null): array
    {
        $hashtags = $this->normalizeStringList($criteria['hashtags'] ?? [], 5, true);
        $optionalHashtags = $this->normalizeStringList($criteria['optionalHashtags'] ?? [], 4, true);
        $primaryHashtag = trim((string) ($criteria['primaryHashtag'] ?? ''));
        $primaryHashtag = ltrim($primaryHashtag, '#');
        $primaryHashtag = preg_replace('/\s+/', ' ', $primaryHashtag) ?? $primaryHashtag;
        if ($primaryHashtag !== '') {
            array_unshift($hashtags, $primaryHashtag);
        }
        $hashtags = array_values(array_slice(array_unique(array_filter(array_merge($hashtags, $optionalHashtags))), 0, 5));
        if ($hashtags === []) {
            $hashtags = $this->hashtagsFromTerms(array_merge(
                (array) ($criteria['searchTerms'] ?? []),
                (array) ($criteria['nicheKeywords'] ?? []),
                $this->contextKeywords((string) ($context['projectContext'] ?? '')),
            ));
        }
        $primaryHashtag = $hashtags[0] ?? $primaryHashtag;
        $optionalHashtags = array_values(array_slice(array_filter($hashtags, fn (string $tag) => $tag !== $primaryHashtag), 0, 4));

        $searchTerms = $this->normalizeStringList($criteria['searchTerms'] ?? [], 10, false);
        if ($searchTerms === []) {
            $searchTerms = $this->normalizeStringList(array_merge([$brief], $hashtags), 8, false);
        }

        $nicheKeywords = $this->normalizeStringList($criteria['nicheKeywords'] ?? [], 14, false);
        if ($nicheKeywords === []) {
            $nicheKeywords = $this->normalizeStringList(array_merge($searchTerms, $hashtags, $this->contextKeywords((string) ($context['projectContext'] ?? ''))), 14, false);
        }

        $languageHints = $this->normalizeLanguageHints($criteria['languageHints'] ?? []);
        $mustHaveSignals = $this->normalizeStringList($criteria['mustHaveSignals'] ?? [], 8, false);
        $avoidSignals = $this->normalizeStringList($criteria['avoidSignals'] ?? [], 8, false);
        $notes = $this->normalizeStringList($criteria['notes'] ?? [], 8, false);

        [$followerMin, $followerMax] = $this->normalizeFollowerBounds(
            (int) ($criteria['followerMin'] ?? 0),
            (int) ($criteria['followerMax'] ?? 0),
            $brief,
            $context,
        );

        $activeWithinDays = max(0, min(365, (int) ($criteria['activeWithinDays'] ?? 30)));
        $genderTarget = $this->normalizeGenderTarget((string) ($criteria['genderTarget'] ?? 'any'));
        $locationText = trim((string) ($criteria['locationText'] ?? ''));
        $summary = trim((string) ($criteria['summary'] ?? '')) ?: trim($brief);
        $productOrOffer = trim((string) ($criteria['productOrOffer'] ?? ''));
        $audienceDescription = trim((string) ($criteria['audienceDescription'] ?? ''));
        $discoveryLimit = max(20, min(200, (int) ($criteria['recommendedDiscoveryLimit'] ?? 60)));
        $enrichmentLimit = max(10, min(80, (int) ($criteria['recommendedEnrichmentLimit'] ?? 25)));

        if ($fallbackNote) {
            array_unshift($notes, 'AI parsing failed, fallback heuristics were used.');
        }

        if ($genderTarget !== 'any') {
            $notes[] = 'Gender is inferred from public profile signals only and should not be treated as certain.';
        }
        if ($locationText !== '') {
            $notes[] = 'Location is inferred from public profile text, explicit fields, and language hints where available.';
        }
        if ($primaryHashtag !== '') {
            $notes[] = 'Primary hashtag should be broad enough to seed discovery; optional hashtags should be used to tighten fit.';
        }

        return [
            'brief' => trim($brief),
            'platform' => strtolower(trim((string) ($context['platform'] ?? 'instagram'))),
            'summary' => $summary,
            'productOrOffer' => $productOrOffer,
            'audienceDescription' => $audienceDescription,
            'locationText' => $locationText,
            'locationInferred' => (bool) ($criteria['locationInferred'] ?? false),
            'genderTarget' => $genderTarget,
            'genderInferred' => (bool) ($criteria['genderInferred'] ?? false),
            'followerMin' => $followerMin,
            'followerMax' => $followerMax,
            'includeSub1kCreators' => false,
            'minimumFollowerFloor' => 1000,
            'activeWithinDays' => $activeWithinDays,
            'primaryHashtag' => $primaryHashtag,
            'optionalHashtags' => $optionalHashtags,
            'hashtags' => $hashtags,
            'searchTerms' => $searchTerms,
            'nicheKeywords' => $nicheKeywords,
            'languageHints' => $languageHints,
            'mustHaveSignals' => $mustHaveSignals,
            'avoidSignals' => $avoidSignals,
            'recommendedDiscoveryLimit' => $discoveryLimit,
            'recommendedEnrichmentLimit' => $enrichmentLimit,
            'notes' => array_values(array_unique($notes)),
        ];
    }

    private function fallbackCriteria(string $brief, array $context, ?string $failureMessage = null): array
    {
        $keywords = $this->seedKeywordsFromText($brief, (string) ($context['projectContext'] ?? ''));
        $hashtags = $this->hashtagsFromTerms($keywords);
        $primaryHashtag = $hashtags[0] ?? '';
        $optionalHashtags = array_values(array_slice(array_filter($hashtags, fn (string $tag) => $tag !== $primaryHashtag), 0, 4));
        [$followerMin, $followerMax] = $this->normalizeFollowerBounds(0, 0, $brief, $context);

        $notes = ['Fallback parsing was used. Review generated hashtags before trusting them blindly.'];
        if ($failureMessage) {
            $notes[] = 'Parser fallback reason: '.$failureMessage;
        }

        return [
            'brief' => trim($brief),
            'platform' => strtolower(trim((string) ($context['platform'] ?? 'instagram'))),
            'summary' => trim($brief),
            'productOrOffer' => '',
            'audienceDescription' => '',
            'locationText' => '',
            'locationInferred' => false,
            'genderTarget' => 'any',
            'genderInferred' => false,
            'followerMin' => $followerMin,
            'followerMax' => $followerMax,
            'minimumFollowerFloor' => 1000,
            'activeWithinDays' => 30,
            'primaryHashtag' => $primaryHashtag,
            'optionalHashtags' => $optionalHashtags,
            'hashtags' => $hashtags,
            'searchTerms' => array_values(array_slice($keywords, 0, 8)),
            'nicheKeywords' => array_values(array_slice($keywords, 0, 12)),
            'languageHints' => [],
            'mustHaveSignals' => [],
            'avoidSignals' => [],
            'recommendedDiscoveryLimit' => 60,
            'recommendedEnrichmentLimit' => 25,
            'notes' => $notes,
        ];
    }

    private function normalizeFollowerBounds(int $min, int $max, string $brief, array $context): array
    {
        $min = max(0, $min);
        $max = max(0, $max);
        if ($max > 0 && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        if ($min > 0 || $max > 0) {
            return [$min, $max];
        }

        $haystack = strtolower(trim($brief.' '.(string) ($context['projectContext'] ?? '')));
        $bands = [
            ['tokens' => ['nano creator', 'nano creators', 'under 10k', 'under 10000'], 'min' => 1000, 'max' => 10000],
            ['tokens' => ['micro creator', 'micro creators', 'micro-influencer', 'micro influencers'], 'min' => 5000, 'max' => 50000],
            ['tokens' => ['mid-tier', 'mid tier', 'midsize', 'mid-sized'], 'min' => 50000, 'max' => 250000],
            ['tokens' => ['macro', 'macro creator', 'macro creators'], 'min' => 250000, 'max' => 1000000],
            ['tokens' => ['luxury', 'premium', 'celebrity'], 'min' => 100000, 'max' => 0],
        ];

        foreach ($bands as $band) {
            foreach ($band['tokens'] as $token) {
                if (str_contains($haystack, $token)) {
                    return [$band['min'], $band['max']];
                }
            }
        }

        return [0, 0];
    }

    private function seedKeywordsFromText(string ...$texts): array
    {
        $keywords = [];
        foreach ($texts as $text) {
            $clean = strtolower(trim($text));
            if ($clean === '') {
                continue;
            }

            $clean = preg_replace('/[^a-z0-9äöüß\-\s]+/iu', ' ', $clean) ?? $clean;
            $parts = preg_split('/[\s,\n]+/', $clean) ?: [];
            $buffer = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '' || strlen($part) < 3 || in_array($part, ['with', 'that', 'this', 'your', 'their', 'from', 'into', 'about', 'need', 'looking', 'find', 'creator', 'creators'], true)) {
                    continue;
                }
                $buffer[] = $part;
            }

            $keywords = array_merge($keywords, $buffer);
        }

        return array_values(array_unique($keywords));
    }

    private function contextKeywords(string $context): array
    {
        return array_values(array_slice($this->seedKeywordsFromText($context), 0, 12));
    }

    private function normalizeStringList(array $values, int $maxItems, bool $stripHash): array
    {
        $items = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $item = trim((string) $value);
            if ($item === '') {
                continue;
            }
            if ($stripHash) {
                $item = ltrim($item, '#');
            }
            $item = preg_replace('/\s+/', ' ', $item) ?? $item;
            if ($item === '') {
                continue;
            }
            $items[] = $item;
        }

        return array_values(array_slice(array_unique($items), 0, $maxItems));
    }

    private function normalizeLanguageHints(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $token = strtolower(trim((string) $value));
            if ($token === '') {
                continue;
            }
            $normalized[] = match ($token) {
                'german', 'deutsch', 'deutschland' => 'de',
                'english', 'englisch' => 'en',
                default => substr($token, 0, 5),
            };
        }

        return array_values(array_slice(array_unique($normalized), 0, 5));
    }

    private function normalizeGenderTarget(string $value): string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'female', 'woman', 'women', 'girls', 'girl' => 'female',
            'male', 'man', 'men', 'boys', 'boy' => 'male',
            'mixed', 'any', 'all', '' => 'any',
            default => 'any',
        };
    }

    private function hashtagsFromTerms(array $terms): array
    {
        $hashtags = [];
        foreach ($terms as $term) {
            if (! is_scalar($term)) {
                continue;
            }
            $clean = strtolower(trim((string) $term));
            $clean = preg_replace('/[^a-z0-9äöüß ]+/iu', ' ', $clean) ?? $clean;
            $parts = preg_split('/\s+/', $clean) ?: [];
            $parts = array_values(array_filter($parts, fn (string $part) => strlen($part) > 2));
            if ($parts === []) {
                continue;
            }

            $candidate = implode('', array_slice($parts, 0, 3));
            if ($candidate !== '' && strlen($candidate) <= 28) {
                $hashtags[] = $candidate;
            }

            if (count($parts) >= 2) {
                $hashtags[] = $parts[0].$parts[1];
            }
        }

        return array_values(array_slice(array_unique($hashtags), 0, 5));
    }
}
