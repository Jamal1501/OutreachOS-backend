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
            $creator['priorityScore'] = $this->priorityScoreFor($creator, $evaluation);

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

            $priorityA = (float) ($a['priorityScore'] ?? 0);
            $priorityB = (float) ($b['priorityScore'] ?? 0);
            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA;
            }

            $scoreA = (float) ($a['fitScore'] ?? 0);
            $scoreB = (float) ($b['fitScore'] ?? 0);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            $valueA = (float) ($a['valueScore'] ?? 0);
            $valueB = (float) ($b['valueScore'] ?? 0);
            if ($valueA !== $valueB) {
                return $valueB <=> $valueA;
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
        $hardRequested = 0;
        $softRequested = 0;
        $softMatches = 0;
        $weightedTotal = 0.0;
        $weightedScore = 0.0;

        $followers = $this->nullableInt($creator['followers'] ?? null);
        $minimumFollowerFloor = $this->minimumFollowerFloor($criteria);
        if ($minimumFollowerFloor > 0) {
            $hardRequested++;
            $weightedTotal += 25;

            if ($followers === null) {
                $hardFailures[] = 'missing_followers';
                $rejectReasons[] = 'Follower count is missing.';
            } elseif ($followers < $minimumFollowerFloor) {
                $hardFailures[] = 'below_default_follower_floor';
                $rejectReasons[] = 'Below the default 1k follower floor.';
            } else {
                $weightedScore += 25;
            }
        }

        $followerMin = max(0, (int) ($criteria['followerMin'] ?? 0));
        $followerMax = max(0, (int) ($criteria['followerMax'] ?? 0));
        if ($followerMin > 0 || $followerMax > 0) {
            $hardRequested++;
            $weightedTotal += 45;
            $rangeAccuracy = $this->followerRangeAccuracy($followers, $followerMin, $followerMax);
            $weightedScore += $rangeAccuracy * 0.45;

            if ($followers === null) {
                $hardFailures[] = 'missing_followers';
                $rejectReasons[] = 'Follower count is missing.';
            } elseif ($followerMin > 0 && $followers < $followerMin) {
                $hardFailures[] = 'followers_below_min';
                $rejectReasons[] = 'Followers below requested minimum.';
            } elseif ($followerMax > 0 && $followers > $followerMax) {
                $hardFailures[] = 'followers_above_max';
                $rejectReasons[] = 'Followers above requested maximum.';
            } else {
                $matchReasons[] = 'Follower range matches.';
            }
        }

        $activeWithinDays = max(0, (int) ($criteria['activeWithinDays'] ?? 0));
        $latestPostAt = trim((string) ($creator['latestPostAt'] ?? ''));
        if ($activeWithinDays > 0) {
            $hardRequested++;
            $weightedTotal += 20;
            $latest = $latestPostAt !== '' ? $this->parseDate($latestPostAt) : null;
            if (!$latest) {
                $hardFailures[] = 'missing_recent_post';
                $rejectReasons[] = 'Recent activity could not be verified.';
            } else {
                $ageDays = $latest->diffInDays(CarbonImmutable::now(), false);
                $activityAccuracy = $ageDays <= $activeWithinDays
                    ? 100
                    : max(0, 100 - ((int) ceil((($ageDays - $activeWithinDays) / max(7, $activeWithinDays)) * 100)));
                $weightedScore += $activityAccuracy * 0.20;

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
            $weightedTotal += 10;
            if ($this->matchesLocation($creator, $locationText, (array) ($criteria['languageHints'] ?? []))) {
                $softMatches++;
                $weightedScore += 10;
                $matchReasons[] = 'Location signals fit.';
            } else {
                $rejectReasons[] = 'No convincing location signal found.';
            }
        }

        $genderTarget = strtolower(trim((string) ($criteria['genderTarget'] ?? 'any')));
        if ($genderTarget !== '' && $genderTarget !== 'any') {
            $softRequested++;
            $weightedTotal += 10;
            if ($this->matchesGender($creator, $genderTarget)) {
                $softMatches++;
                $weightedScore += 10;
                $matchReasons[] = 'Gender signals fit.';
            } else {
                $rejectReasons[] = 'No convincing gender signal found.';
            }
        }

        $languageHints = array_values(array_filter((array) ($criteria['languageHints'] ?? [])));
        if ($languageHints !== []) {
            $softRequested++;
            $weightedTotal += 15;
            if ($this->matchesLanguage($creator, $languageHints)) {
                $softMatches++;
                $weightedScore += 15;
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
        $nicheRequested = $nicheKeywords !== [];
        $nicheMatched = false;
        if ($nicheRequested) {
            $softRequested++;
            $weightedTotal += 25;
            if ($this->matchesNiche($creator, $nicheKeywords, (array) ($criteria['avoidSignals'] ?? []))) {
                $softMatches++;
                $nicheMatched = true;
                $weightedScore += 25;
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

        $rawAccuracy = $weightedTotal > 0 ? ($weightedScore / $weightedTotal) * 100 : ($passesHardFilters ? 60 : 0);
        $evidenceCap = match (true) {
            $softRequested <= 0 && $hardRequested > 0 => 84,
            $softRequested <= 0 && $hardRequested <= 0 => 60,
            $softRequested === 1 => 92,
            default => 100,
        };
        $matchAccuracy = (int) round(min($evidenceCap, $rawAccuracy));

        $fitScore = $matchAccuracy;
        if (!$passesHardFilters) {
            $fitScore = max(0, $fitScore - 25);
        } elseif ($softRequested > 0 && !$passesSoftFilters) {
            $fitScore = max(0, $fitScore - 10);
        }
        $fitScore = max(0, min(100, $fitScore));

        $matchCategory = 'weak';
        if ($passesHardFilters) {
            if ($softRequested === 0) {
                $matchCategory = 'partial';
            } elseif ($passesSoftFilters && (!$nicheRequested || $nicheMatched) && $matchAccuracy >= 75) {
                $matchCategory = 'full';
            } elseif ($softMatches > 0 || $matchAccuracy >= 55) {
                $matchCategory = 'partial';
            }
        }

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

    private function priorityScoreFor(array $creator, array $evaluation): int
    {
        $fitScore = (float) ($evaluation['fitScore'] ?? 0);
        $valueScore = max(0, min(100, (float) ($creator['valueScore'] ?? 0)));

        return (int) round(($fitScore * 0.70) + ($valueScore * 0.30));
    }

    private function followerRangeAccuracy(?int $followers, int $min, int $max): int
    {
        if ($min === 0 && $max === 0) {
            return $followers !== null ? 70 : 0;
        }

        if ($followers === null || $followers <= 0) {
            return 0;
        }

        $lower = $min > 0 ? $min : 0;
        $upper = $max > 0 ? $max : PHP_INT_MAX;
        if ($followers >= $lower && $followers <= $upper) {
            return 100;
        }

        $target = $followers < $lower ? $lower : $upper;
        $distance = abs($followers - $target);
        $base = max(1, $target);
        $ratio = $distance / $base;

        return match (true) {
            $ratio <= 0.15 => 70,
            $ratio <= 0.35 => 40,
            $ratio <= 0.60 => 15,
            default => 0,
        };
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
        $haystack = $this->creatorSearchText($creator);
        $keywordSet = $this->normalizeKeywordSet($keywords);
        $avoidSet = $this->normalizeKeywordSet($avoidSignals);

        foreach ($avoidSet as $avoid) {
            if ($avoid !== '' && str_contains($haystack, $avoid)) {
                return false;
            }
        }

        if ($keywordSet === []) {
            return false;
        }

        $matches = 0;
        foreach ($keywordSet as $keyword) {
            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                $matches++;
            }
        }

        return $matches >= 2 || ($matches >= 1 && count($keywordSet) <= 2);
    }

    private function creatorSearchText(array $creator): string
    {
        $chunks = [
            (string) ($creator['bio'] ?? ''),
            (string) ($creator['fullName'] ?? ''),
            (string) ($creator['locationHint'] ?? ''),
            (string) ($creator['sourceLocationHint'] ?? ''),
            implode(' ', array_map('strval', (array) ($creator['sourceHashtags'] ?? []))),
            implode(' ', array_map('strval', (array) ($creator['nicheHints'] ?? []))),
            implode(' ', array_map('strval', (array) ($creator['languageHints'] ?? []))),
        ];

        return strtolower(implode(' ', array_filter(array_map('trim', $chunks))));
    }

    private function normalizeKeywordSet(array $values): array
    {
        $keywords = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = strtolower(trim((string) $value));
            if ($value === '') {
                continue;
            }

            $value = ltrim($value, '#');
            $value = preg_replace('/[^a-z0-9äöüß\-\s]+/iu', ' ', $value) ?? $value;
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;

            foreach (preg_split('/\s+/', $value) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '' && strlen($part) >= 3 && !in_array($part, ['and', 'with', 'for', 'the', 'und', 'mit'], true)) {
                    $keywords[] = $part;
                }
            }

            if (strlen(str_replace(' ', '', $value)) >= 6) {
                $keywords[] = $value;
            }
        }

        return array_values(array_unique($keywords));
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

    private function minimumFollowerFloor(array $criteria): int
    {
        if ((bool) ($criteria['includeSub1kCreators'] ?? false)) {
            return 0;
        }

        return max(0, (int) ($criteria['minimumFollowerFloor'] ?? 1000));
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
        if ($this->minimumFollowerFloor($criteria) > 0) {
            $filters[] = 'minimum_followers';
        }
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
