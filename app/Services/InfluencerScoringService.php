<?php

namespace App\Services;

use Carbon\Carbon;

class InfluencerScoringService
{
    public function score(array $creator, ?array $sourceRow = null, array $criteria = []): int
    {
        return $this->detailedScore($creator, $sourceRow, $criteria)['score'];
    }

    public function detailedScore(array $creator, ?array $sourceRow = null, array $criteria = []): array
    {
        $followers = $this->toFloat($creator['followers'] ?? $creator['Followers'] ?? $sourceRow['followersCount'] ?? $sourceRow['Followers'] ?? 0);
        $engagement = $this->resolveEngagementRate($creator, $sourceRow);
        $contentQuantity = $this->toFloat(
            $creator['postsCount']
                ?? $sourceRow['postsCount']
                ?? $sourceRow['videoCount']
                ?? $sourceRow['latestPosts_count']
                ?? $sourceRow['recent_posts_used_for_engagement']
                ?? $sourceRow['recent_posts_used']
                ?? 0
        );
        $avgLikes = $this->toFloat($creator['avgLikes'] ?? $sourceRow['recent_avg_likes'] ?? 0);
        $avgComments = $this->toFloat($creator['avgComments'] ?? $sourceRow['recent_avg_comments'] ?? 0);
        $hasEmail = trim((string) ($creator['email'] ?? $sourceRow['email'] ?? '')) !== '';
        $isVerified = (bool) ($creator['isVerified'] ?? $sourceRow['isVerified'] ?? false);
        $recencyDays = $this->resolveRecencyDays($creator, $sourceRow);

        $signals = [];
        $risks = [];

        [$followerScore, $followerSignal, $followerRisk] = $this->scoreFollowerFit($followers, $criteria);
        if ($followerSignal) {
            $signals[] = $followerSignal;
        }
        if ($followerRisk) {
            $risks[] = $followerRisk;
        }

        $engagementScore = match (true) {
            $engagement >= 8 => 22,
            $engagement >= 5 => 18,
            $engagement >= 3 => 13,
            $engagement >= 1.5 => 8,
            $engagement > 0 => 4,
            default => 0,
        };
        if ($engagement >= 5) {
            $signals[] = 'Engagement is strong for outreach.';
        } elseif ($engagement <= 0) {
            $risks[] = 'Engagement rate missing or unusable.';
        }

        $quantityScore = match (true) {
            $contentQuantity >= 24 => 12,
            $contentQuantity >= 12 => 9,
            $contentQuantity >= 6 => 6,
            $contentQuantity >= 1 => 3,
            default => 0,
        };
        if ($contentQuantity >= 12) {
            $signals[] = 'Content output is consistently usable.';
        } elseif ($contentQuantity < 3) {
            $risks[] = 'Very thin recent content sample.';
        }

        $recencyScore = match (true) {
            $recencyDays === null => 0,
            $recencyDays <= 14 => 10,
            $recencyDays <= 30 => 7,
            $recencyDays <= 60 => 3,
            default => 0,
        };
        if ($recencyDays !== null && $recencyDays <= 30) {
            $signals[] = 'Recent posting activity is healthy.';
        } elseif ($recencyDays !== null && $recencyDays > 60) {
            $risks[] = 'Recent activity looks stale.';
        }

        $contactScore = $hasEmail ? 8 : 2;
        if ($hasEmail) {
            $signals[] = 'Direct contact info exists.';
        } else {
            $risks[] = 'No direct email/contact signal.';
        }

        $verificationScore = $isVerified ? 3 : 0;
        if ($isVerified) {
            $signals[] = 'Verified account adds some trust.';
        }

        [$nicheScore, $nicheSignals, $nicheRisks] = $this->scoreNicheFit($creator, $criteria);
        $signals = array_merge($signals, $nicheSignals);
        $risks = array_merge($risks, $nicheRisks);

        $suspicionPenalty = 0;
        if ($followers > 0 && $avgLikes > 0) {
            $likeRatio = $avgLikes / max(1, $followers);
            if ($likeRatio > 0.3 && $followers > 5000) {
                $suspicionPenalty += 4;
                $risks[] = 'Engagement pattern looks unusually high for the follower count.';
            }
        }
        if ($followers > 0 && $avgComments > 0) {
            $commentRatio = $avgComments / max(1, $followers);
            if ($commentRatio > 0.08) {
                $suspicionPenalty += 3;
                $risks[] = 'Comment ratio looks suspiciously high.';
            }
        }

        $score = $followerScore + $engagementScore + $quantityScore + $recencyScore + $contactScore + $verificationScore + $nicheScore - $suspicionPenalty;
        $score = (int) max(0, min(100, round($score)));

        return [
            'score' => $score,
            'tier' => $this->tier($score),
            'signals' => array_values(array_unique($signals)),
            'risks' => array_values(array_unique($risks)),
        ];
    }

    public function tier(int|float|string|null $score): string
    {
        $score = (float) $score;

        if ($score >= 70) {
            return 'HIGH';
        }

        if ($score >= 40) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    public function bar(int|float|string|null $score): string
    {
        $score = max(0, min(100, (float) $score));
        $filled = (int) round($score / 10);
        return str_repeat('█', $filled) . str_repeat('░', max(0, 10 - $filled));
    }

    private function scoreFollowerFit(float $followers, array $criteria): array
    {
        $min = max(0, (int) ($criteria['followerMin'] ?? 0));
        $max = max(0, (int) ($criteria['followerMax'] ?? 0));
        $minimumFollowerFloor = $this->minimumFollowerFloor($criteria);

        if ($followers <= 0) {
            return [0, null, 'Follower count missing.'];
        }

        if ($min > 0 || $max > 0) {
            $lower = $min > 0 ? $min : 0;
            $upper = $max > 0 ? $max : PHP_INT_MAX;
            if ($followers >= $lower && $followers <= $upper) {
                return [28, 'Follower range fits the requested brief.', null];
            }

            $target = $followers < $lower ? $lower : $upper;
            $distance = abs($followers - $target);
            $base = max(1, $target);
            $ratio = $distance / $base;

            return match (true) {
                $ratio <= 0.15 => [20, 'Follower range is close to the requested brief.', null],
                $ratio <= 0.35 => [12, null, 'Follower range is slightly outside the requested brief.'],
                $ratio <= 0.60 => [5, null, 'Follower range is noticeably outside the requested brief.'],
                default => [0, null, 'Follower range is far outside the requested brief.'],
            };
        }

        if ($minimumFollowerFloor > 0 && $followers < $minimumFollowerFloor) {
            return [0, null, 'Below the default 1k follower floor.'];
        }

        if ($followers < 1000) {
            return [6, 'Emerging creator under 1k followers.', null];
        }

        $score = (int) round(min(24, max(12, 12 + ((log10(max(1000, $followers)) - 3) * 4.5))));

        return [$score, 'Follower base is established enough for outreach.', null];
    }

    private function scoreNicheFit(array $creator, array $criteria): array
    {
        $haystack = $this->creatorSearchText($creator);
        $keywordSet = $this->campaignKeywordSet($criteria);
        $avoidSet = $this->normalizeKeywordSet((array) ($criteria['avoidSignals'] ?? []));

        $signals = [];
        $risks = [];

        foreach ($avoidSet as $avoid) {
            if ($avoid !== '' && str_contains($haystack, $avoid)) {
                $risks[] = 'Profile text contains avoid-signal terms.';
                return [0, $signals, $risks];
            }
        }

        if ($keywordSet === []) {
            return [0, $signals, $risks];
        }

        $exactMatches = 0;
        foreach ($keywordSet as $keyword) {
            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                $exactMatches++;
            }
        }

        $sourceHashtags = array_map('strtolower', array_values(array_filter((array) ($creator['sourceHashtags'] ?? []))));
        $hashtagOverlap = 0;
        foreach ($sourceHashtags as $tag) {
            if (in_array($tag, $keywordSet, true)) {
                $hashtagOverlap++;
            }
        }

        $score = match (true) {
            $exactMatches >= 5 => 18,
            $exactMatches >= 3 => 13,
            $exactMatches >= 2 => 9,
            $exactMatches >= 1 => 5,
            default => 0,
        };

        if ($hashtagOverlap > 0) {
            $score += min(5, $hashtagOverlap * 2);
        }

        if ($exactMatches >= 3) {
            $signals[] = 'Profile language strongly matches the campaign niche.';
        } elseif ($exactMatches >= 1) {
            $signals[] = 'Profile shows some niche alignment.';
        } else {
            $risks[] = 'Niche alignment is weak.';
        }

        if ($hashtagOverlap > 0) {
            $signals[] = 'Source hashtags overlap with the target brief.';
        }

        return [min(24, $score), $signals, $risks];
    }

    private function campaignKeywordSet(array $criteria): array
    {
        return $this->normalizeKeywordSet(array_merge(
            [$criteria['summary'] ?? null, $criteria['productOrOffer'] ?? null, $criteria['audienceDescription'] ?? null],
            (array) ($criteria['hashtags'] ?? []),
            (array) ($criteria['searchTerms'] ?? []),
            (array) ($criteria['nicheKeywords'] ?? []),
            (array) ($criteria['mustHaveSignals'] ?? []),
            (array) ($criteria['languageHints'] ?? []),
            [$criteria['locationText'] ?? null],
        ));
    }

    private function creatorSearchText(array $creator): string
    {
        $chunks = [
            (string) ($creator['bio'] ?? ''),
            (string) ($creator['fullName'] ?? ''),
            (string) ($creator['locationHint'] ?? ''),
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

    private function minimumFollowerFloor(array $criteria): int
    {
        if ((bool) ($criteria['includeSub1kCreators'] ?? false)) {
            return 0;
        }

        return max(0, (int) ($criteria['minimumFollowerFloor'] ?? 1000));
    }

    private function resolveEngagementRate(array $creator, ?array $sourceRow = null): float
    {
        $fromCreator = $this->toFloat($creator['engagementRate'] ?? $creator['Engagement_Rate_%'] ?? 0);
        if ($fromCreator > 0) {
            return $fromCreator > 1 && $fromCreator <= 100 ? $fromCreator : round($fromCreator * 100, 2);
        }

        $fromIg = $this->toFloat($sourceRow['recent_est_engagement_rate_pct'] ?? 0);
        if ($fromIg > 0) {
            return $fromIg;
        }

        $followers = $this->toFloat($creator['followers'] ?? $creator['Followers'] ?? $sourceRow['followersCount'] ?? 0);
        $avgLikes = $this->toFloat($creator['avgLikes'] ?? $sourceRow['recent_avg_likes'] ?? 0);
        $avgComments = $this->toFloat($creator['avgComments'] ?? $sourceRow['recent_avg_comments'] ?? 0);

        if ($followers > 0 && ($avgLikes > 0 || $avgComments > 0)) {
            return round((($avgLikes + $avgComments) / $followers) * 100, 2);
        }

        return 0;
    }

    private function resolveRecencyDays(array $creator, ?array $sourceRow = null): ?int
    {
        $candidates = [
            trim((string) ($creator['Last_Content_Date'] ?? '')),
            trim((string) ($creator['lastContentAt'] ?? '')),
            trim((string) ($sourceRow['lastContentAt'] ?? '')),
        ];

        foreach ($candidates as $value) {
            if ($value === '') {
                continue;
            }
            try {
                return Carbon::parse($value)->diffInDays(now());
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '=')) {
            return 0;
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $value ?? '');
        return is_numeric($normalized) ? (float) $normalized : 0;
    }
}
