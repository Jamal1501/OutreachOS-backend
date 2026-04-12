<?php

namespace App\Services;

class AiDiscoveryBriefService
{
    public function __construct(private AiGatewayService $ai)
    {
    }

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

        $systemPrompt = 'You turn a messy creator-search brief into strict structured discovery criteria for an outreach tool. Be literal, commercially useful, and skeptical. Do not invent impossible certainty. For gender, location, and language, mark inferred fields honestly. Return exactly 1 primary hashtag and up to 3 optional hashtags tailored to the product and platform. Keep hashtags short and public-search friendly. Prefer micro-creator discovery, not celebrity tags. Keep nicheKeywords, mustHaveSignals, and avoidSignals to at most 1 item each. Do not flood the response with redundant synonyms.';

        $userPrompt = "Platform: {$platform}\n"
            . ($projectContext !== '' ? "Project context:\n{$projectContext}\n\n" : '')
            . "User brief:\n{$brief}\n\n"
            . "Return structured discovery criteria for an influencer discovery pipeline."
            . "Follower bounds should be integers or 0 if not specified."
            . "If activity recency is missing, use 30."
            . "If gender or location is not explicit, keep the target but mark it inferred if the user wording strongly implies it; otherwise return 'any' or empty location.";

        try {
            $result = $this->ai->structured(
                $systemPrompt,
                $userPrompt,
                'submit_discovery_brief',
                'Return structured discovery criteria for creator outreach.',
                $schema,
                0.2,
            );
        } catch (\Throwable $e) {
            return $this->fallbackCriteria($brief, $context, $e->getMessage());
        }

        return $this->normalizeCriteria($result, $brief, $context);
    }

    private function normalizeCriteria(array $criteria, string $brief, array $context, ?string $fallbackNote = null): array
    {
        $hashtags = $this->normalizeStringList($criteria['hashtags'] ?? [], 4, true);
        $searchTerms = $this->normalizeStringList($criteria['searchTerms'] ?? [], 10, false);
        $nicheKeywords = $this->normalizeStringList($criteria['nicheKeywords'] ?? [], 1, false);
        $languageHints = $this->normalizeLanguageHints($criteria['languageHints'] ?? []);
        $mustHaveSignals = $this->normalizeStringList($criteria['mustHaveSignals'] ?? [], 1, false);
        $avoidSignals = $this->normalizeStringList($criteria['avoidSignals'] ?? [], 1, false);
        $notes = $this->normalizeStringList($criteria['notes'] ?? [], 8, false);

        if ($hashtags === []) {
            $hashtags = $this->hashtagsFromTerms(array_merge($searchTerms, $nicheKeywords));
        }

        $followerMin = max(0, (int) ($criteria['followerMin'] ?? 0));
        $followerMax = max(0, (int) ($criteria['followerMax'] ?? 0));
        $explicitFollowerBounds = $this->extractFollowerBoundsFromBrief($brief);
        if ($explicitFollowerBounds !== null) {
            $followerMin = $explicitFollowerBounds['min'];
            $followerMax = $explicitFollowerBounds['max'];
        }
        if ($followerMax > 0 && $followerMin > 0 && $followerMax < $followerMin) {
            [$followerMin, $followerMax] = [$followerMax, $followerMin];
        }

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

        $normalized = [
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
            'activeWithinDays' => $activeWithinDays,
            'hashtags' => $hashtags,
            'primaryHashtag' => $hashtags[0] ?? null,
            'optionalHashtags' => array_values(array_slice($hashtags, 1, 3)),
            'searchTerms' => $searchTerms,
            'nicheKeywords' => $nicheKeywords,
            'languageHints' => $languageHints,
            'mustHaveSignals' => $mustHaveSignals,
            'avoidSignals' => $avoidSignals,
            'recommendedDiscoveryLimit' => $discoveryLimit,
            'recommendedEnrichmentLimit' => $enrichmentLimit,
            'notes' => array_values(array_unique($notes)),
        ];

        return $normalized;
    }


    private function extractFollowerBoundsFromBrief(string $brief): ?array
    {
        $brief = strtolower(trim($brief));
        if ($brief === '') {
            return null;
        }

        $patterns = [
            '/(?:between|from)\s+([\d.,]+\s*[km]?)\s*(?:and|to|-)\s*([\d.,]+\s*[km]?)/iu',
            '/([\d.,]+\s*[km]?)\s*(?:-|to)\s*([\d.,]+\s*[km]?)\s+followers?/iu',
            '/followers?\s*(?:between|from)?\s*([\d.,]+\s*[km]?)\s*(?:and|to|-)\s*([\d.,]+\s*[km]?)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $brief, $matches) === 1) {
                $min = $this->parseHumanNumber((string) ($matches[1] ?? ''));
                $max = $this->parseHumanNumber((string) ($matches[2] ?? ''));
                if ($min > 0 && $max > 0) {
                    if ($max < $min) {
                        [$min, $max] = [$max, $min];
                    }
                    return ['min' => $min, 'max' => $max];
                }
            }
        }

        return null;
    }

    private function parseHumanNumber(string $value): int
    {
        $normalized = strtolower(trim(str_replace(['followers', 'follower', 'subs', 'subscribers', ' ', ','], '', $value)));
        if ($normalized === '') {
            return 0;
        }

        $multiplier = 1;
        if (str_ends_with($normalized, 'k')) {
            $multiplier = 1000;
            $normalized = substr($normalized, 0, -1);
        } elseif (str_ends_with($normalized, 'm')) {
            $multiplier = 1000000;
            $normalized = substr($normalized, 0, -1);
        }

        $normalized = str_replace('.', '.', $normalized);
        if (!is_numeric($normalized)) {
            return 0;
        }

        return (int) round(((float) $normalized) * $multiplier);
    }

    private function fallbackCriteria(string $brief, array $context, ?string $failureMessage = null): array
    {
        $terms = preg_split('/[,\n]+/', strtolower($brief)) ?: [];
        $keywords = [];
        foreach ($terms as $term) {
            $clean = trim(preg_replace('/[^a-z0-9äöüß\- ]+/iu', ' ', $term) ?? '');
            if ($clean !== '') {
                $keywords[] = $clean;
            }
        }

        $hashtags = $this->hashtagsFromTerms($keywords);
        $notes = ['Fallback parsing was used. Review generated hashtags before trusting them blindly.'];
        if ($failureMessage) {
            $notes[] = 'Parser fallback reason: ' . $failureMessage;
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
            'followerMin' => 0,
            'followerMax' => 0,
            'activeWithinDays' => 30,
            'hashtags' => $hashtags,
            'primaryHashtag' => $hashtags[0] ?? null,
            'optionalHashtags' => array_values(array_slice($hashtags, 1, 3)),
            'searchTerms' => $keywords,
            'nicheKeywords' => $keywords,
            'languageHints' => [],
            'mustHaveSignals' => [],
            'avoidSignals' => [],
            'recommendedDiscoveryLimit' => 60,
            'recommendedEnrichmentLimit' => 25,
            'notes' => $notes,
        ];
    }

    private function normalizeStringList(array $values, int $maxItems, bool $stripHash): array
    {
        $items = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
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
            if (!is_scalar($value)) {
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
            if (!is_scalar($term)) {
                continue;
            }
            $clean = strtolower(trim((string) $term));
            $clean = preg_replace('/[^a-z0-9äöüß ]+/iu', ' ', $clean) ?? $clean;
            $parts = preg_split('/\s+/', $clean) ?: [];
            $candidate = implode('', array_filter($parts, fn (string $part) => strlen($part) > 2));
            if ($candidate !== '' && strlen($candidate) <= 28) {
                $hashtags[] = $candidate;
            }
        }

        return array_values(array_slice(array_unique($hashtags), 0, 4));
    }
}
