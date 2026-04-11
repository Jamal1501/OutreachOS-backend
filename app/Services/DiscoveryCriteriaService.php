<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class DiscoveryCriteriaService
{
    public function apply(array $creators, array $criteria): array
    {
        if ($creators === []) {
            return [
                'creators' => [],
                'summary' => [
                    'inputCount' => 0,
                    'outputCount' => 0,
                    'droppedCount' => 0,
                    'hardFilteredCount' => 0,
                    'softFilteredCount' => 0,
                    'hardFilters' => $this->hardFilterList($criteria),
                    'softFilters' => $this->softFilterList($criteria),
                ],
            ];
        }

        $annotated = [];
        $hardFiltered = 0;
        $softFiltered = 0;
        $fullMatchCount = 0;
        $partialMatchCount = 0;
        $weakMatchCount = 0;

        foreach ($creators as $creator) {
            if (!is_array($creator)) {
                continue;
            }

            $evaluation = $this->evaluateCreator($creator, $criteria);
            $creator['fitEvaluation'] = $evaluation;
            $creator['fitScore'] = $evaluation['fitScore'];
            $creator['matchAccuracy'] = $evaluation['matchAccuracy'];
            $creator['matchCategory'] = $evaluation['matchCategory'];
            $creator['recommendedForImport'] = $evaluation['recommendedForImport'];
            $creator['matchReasons'] = $evaluation['matchReasons'];
            $creator['rejectReasons'] = $evaluation['rejectReasons'];

            if (!$evaluation['passesHardFilters']) {
                $hardFiltered++;
            } elseif (!$evaluation['passesSoftFilters']) {
                $softFiltered++;
            }

            if ($evaluation['matchCategory'] === 'full') {
                $fullMatchCount++;
            } elseif ($evaluation['matchCategory'] === 'partial') {
                $partialMatchCount++;
            } else {
                $weakMatchCount++;
            }

            $annotated[] = $creator;
        }

        usort($annotated, function (array $a, array $b) {
            $categoryRank = [
                'full' => 0,
                'partial' => 1,
                'weak' => 2,
            ];

            $rankA = $categoryRank[(string) ($a['matchCategory'] ?? 'weak')] ?? 9;
            $rankB = $categoryRank[(string) ($b['matchCategory'] ?? 'weak')] ?? 9;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            $scoreA = (float) ($a['fitScore'] ?? 0);
            $scoreB = (float) ($b['fitScore'] ?? 0);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return ((int) ($b['followers'] ?? 0)) <=> ((int) ($a['followers'] ?? 0));
        });

        $recommendedCount = $fullMatchCount + $partialMatchCount;

        return [
            'creators' => array_values($annotated),
            'summary' => [
                'inputCount' => count($creators),
                'outputCount' => $recommendedCount,
                'recommendedCount' => $recommendedCount,
                'totalReturnedCount' => count($annotated),
                'droppedCount' => $weakMatchCount,
                'hardFilteredCount' => $hardFiltered,
                'softFilteredCount' => $softFiltered,
                'fullMatchCount' => $fullMatchCount,
                'partialMatchCount' => $partialMatchCount,
                'weakMatchCount' => $weakMatchCount,
                'hardFilters' => $this->hardFilterList($criteria),
                'softFilters' => $this->softFilterList($criteria),
            ],
        ];
    }

    private function evaluateCreator(array $creator, array $criteria): array
    {
        $matchReasons = [];
        $rejectReasons = [];
        $hardFailures = [];
        $softRequested = 0;
        $softMatches = 0;

        $followers = $this->nullableInt($creator['followers'] ?? null);
        $followerMin = max(0, (int) ($criteria['followerMin'] ?? 0));
        $followerMax = max(0, (int) ($criteria['followerMax'] ?? 0));
        if ($followerMin > 0 && ($followers === null || $followers < $followerMin)) {
            $hardFailures[] = 'followers_below_min';
            $rejectReasons[] = 'Followers below requested minimum.';
        }
        if ($followerMax > 0 && ($followers === null || $followers > $followerMax)) {
            $hardFailures[] = 'followers_above_max';
            $rejectReasons[] = 'Followers above requested maximum.';
        }
        if ($followers !== null && ($followerMin > 0 || $followerMax > 0) && $hardFailures === []) {
            $matchReasons[] = 'Follower range matches.';
        }

        $activeWithinDays = max(0, (int) ($criteria['activeWithinDays'] ?? 0));
        $latestPostAt = trim((string) ($creator['latestPostAt'] ?? ''));
        if ($activeWithinDays > 0) {
            $latest = $latestPostAt !== '' ? $this->parseDate($latestPostAt) : null;
            if (!$latest) {
                $hardFailures[] = 'missing_recent_post';
                $rejectReasons[] = 'Recent activity could not be verified.';
            } else {
                $ageDays = $latest->diffInDays(CarbonImmutable::now(), false);
                if ($ageDays > $activeWithinDays) {
                    $hardFailures[] = 'stale_activity';
                    $rejectReasons[] = 'Latest post is older than requested.';
                } else {
                    $matchReasons[] = 'Posted recently enough.';
                }
            }
        }

        $locationText = trim((string) ($criteria['locationText'] ?? ''));
        if ($locationText !== '') {
            $softRequested++;
            if ($this->matchesLocation($creator, $locationText, (array) ($criteria['languageHints'] ?? []))) {
                $softMatches++;
                $matchReasons[] = 'Location signals fit.';
            } else {
                $rejectReasons[] = 'No convincing location signal found.';
            }
        }

        $genderTarget = strtolower(trim((string) ($criteria['genderTarget'] ?? 'any')));
        if ($genderTarget !== '' && $genderTarget !== 'any') {
            $softRequested++;
            if ($this->matchesGender($creator, $genderTarget)) {
                $softMatches++;
                $matchReasons[] = 'Gender signals fit.';
            } else {
                $rejectReasons[] = 'No convincing gender signal found.';
            }
        }

        $languageHints = array_values(array_filter((array) ($criteria['languageHints'] ?? [])));
        if ($languageHints !== []) {
            $softRequested++;
            if ($this->matchesLanguage($creator, $languageHints)) {
                $softMatches++;
                $matchReasons[] = 'Language signals fit.';
            } else {
                $rejectReasons[] = 'Language signals do not fit.';
            }
        }

        $nicheKeywords = array_values(array_filter(array_merge(
            (array) ($criteria['nicheKeywords'] ?? []),
            (array) ($criteria['hashtags'] ?? []),
            (array) ($criteria['mustHaveSignals'] ?? []),
        )));
        if ($nicheKeywords !== []) {
            $softRequested++;
            if ($this->matchesNiche($creator, $nicheKeywords, (array) ($criteria['avoidSignals'] ?? []))) {
                $softMatches++;
                $matchReasons[] = 'Bio/content signals fit the niche.';
            } else {
                $rejectReasons[] = 'Niche match looks weak.';
            }
        }

        $passesHardFilters = $hardFailures === [];
        $requiredSoftMatches = match (true) {
            $softRequested <= 0 => 0,
            $softRequested === 1 => 1,
            $softRequested === 2 => 1,
            default => 2,
        };
        $passesSoftFilters = $softMatches >= $requiredSoftMatches;

        $matchAccuracy = (int) round(($softRequested > 0 ? ($softMatches / $softRequested) : ($passesHardFilters ? 1 : 0)) * 100);

        $fitScore = 50;
        if ($passesHardFilters) {
            $fitScore += 20;
        }
        $fitScore += (int) round(($softRequested > 0 ? ($softMatches / $softRequested) : 1) * 30);
        if (!$passesHardFilters) {
            $fitScore -= 40;
        }
        if (!$passesSoftFilters) {
            $fitScore -= 15;
        }
        $fitScore = max(0, min(100, $fitScore));

        $matchCategory = $passesHardFilters && $passesSoftFilters
            ? 'full'
            : ($passesHardFilters ? 'partial' : 'weak');

        return [
            'passesHardFilters' => $passesHardFilters,
            'passesSoftFilters' => $passesSoftFilters,
            'hardFailures' => $hardFailures,
            'softRequested' => $softRequested,
            'softMatches' => $softMatches,
            'fitScore' => $fitScore,
            'matchAccuracy' => $matchAccuracy,
            'matchCategory' => $matchCategory,
            'recommendedForImport' => $matchCategory !== 'weak',
            'matchReasons' => array_values(array_unique($matchReasons)),
            'rejectReasons' => array_values(array_unique($rejectReasons)),
        ];
    }

    private function matchesLocation(array $creator, string $locationText, array $languageHints): bool
    {
        $haystack = strtolower(implode(' ', array_filter([
            (string) ($creator['locationHint'] ?? ''),
            (string) ($creator['bio'] ?? ''),
            (string) ($creator['fullName'] ?? ''),
            (string) ($creator['sourceLocationHint'] ?? ''),
        ])));

        $tokens = $this->locationTokens($locationText, $languageHints);
        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }

    private function matchesGender(array $creator, string $genderTarget): bool
    {
        $haystack = strtolower(implode(' ', array_filter([
            (string) ($creator['genderHint'] ?? ''),
            (string) ($creator['bio'] ?? ''),
            (string) ($creator['fullName'] ?? ''),
        ])));

        $signals = $genderTarget === 'female'
            ? ['she/her', ' she ', ' her ', 'girl', 'women', 'woman', 'female', 'mama', 'mom', 'frau']
            : ['he/him', ' he ', ' him ', 'boy', 'men', 'man', 'male', 'dad', 'papa', 'herr'];

        foreach ($signals as $signal) {
            if (str_contains($haystack, trim($signal))) {
                return true;
            }
        }

        return false;
    }

    private function matchesLanguage(array $creator, array $languageHints): bool
    {
        $creatorHints = array_map('strtolower', array_filter((array) ($creator['languageHints'] ?? [])));
        foreach ($languageHints as $hint) {
            $hint = strtolower(trim((string) $hint));
            if ($hint !== '' && in_array($hint, $creatorHints, true)) {
                return true;
            }
        }

        return false;
    }

    private function matchesNiche(array $creator, array $keywords, array $avoidSignals): bool
    {
        $haystack = strtolower(implode(' ', array_filter([
            (string) ($creator['bio'] ?? ''),
            implode(' ', array_map('strval', (array) ($creator['sourceHashtags'] ?? []))),
            implode(' ', array_map('strval', (array) ($creator['nicheHints'] ?? []))),
        ])));

        foreach ($avoidSignals as $avoid) {
            $avoid = strtolower(trim((string) $avoid));
            if ($avoid !== '' && str_contains($haystack, $avoid)) {
                return false;
            }
        }

        foreach ($keywords as $keyword) {
            $keyword = strtolower(trim((string) $keyword));
            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function locationTokens(string $locationText, array $languageHints): array
    {
        $normalized = strtolower(trim($locationText));
        $tokens = [$normalized];

        $map = [
            'germany' => ['germany', 'deutschland', 'german', 'berlin', 'hamburg', 'munich', 'münchen', 'cologne', 'köln', 'frankfurt', 'stuttgart', 'düsseldorf', 'de'],
            'deutschland' => ['germany', 'deutschland', 'german', 'berlin', 'hamburg', 'munich', 'münchen', 'cologne', 'köln', 'frankfurt', 'stuttgart', 'düsseldorf', 'de'],
            'austria' => ['austria', 'österreich', 'vienna', 'wien', 'at'],
            'switzerland' => ['switzerland', 'schweiz', 'zurich', 'zürich', 'geneva', 'genf', 'ch'],
            'united states' => ['united states', 'usa', 'us', 'new york', 'los angeles', 'california', 'texas'],
            'uk' => ['uk', 'united kingdom', 'england', 'london', 'manchester'],
        ];

        if (isset($map[$normalized])) {
            $tokens = array_merge($tokens, $map[$normalized]);
        }

        if (in_array('de', array_map('strtolower', $languageHints), true)) {
            $tokens[] = 'deutsch';
            $tokens[] = 'german';
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    private function hardFilterList(array $criteria): array
    {
        $filters = [];
        if ((int) ($criteria['followerMin'] ?? 0) > 0 || (int) ($criteria['followerMax'] ?? 0) > 0) {
            $filters[] = 'followers';
        }
        if ((int) ($criteria['activeWithinDays'] ?? 0) > 0) {
            $filters[] = 'recent_activity';
        }

        return $filters;
    }

    private function softFilterList(array $criteria): array
    {
        $filters = [];
        if (trim((string) ($criteria['locationText'] ?? '')) !== '') {
            $filters[] = 'location';
        }
        if (strtolower(trim((string) ($criteria['genderTarget'] ?? 'any'))) !== 'any') {
            $filters[] = 'gender';
        }
        if (array_values(array_filter((array) ($criteria['languageHints'] ?? []))) !== []) {
            $filters[] = 'language';
        }
        if (array_values(array_filter((array) ($criteria['nicheKeywords'] ?? []))) !== [] || array_values(array_filter((array) ($criteria['hashtags'] ?? []))) !== []) {
            $filters[] = 'niche';
        }

        return $filters;
    }
}
