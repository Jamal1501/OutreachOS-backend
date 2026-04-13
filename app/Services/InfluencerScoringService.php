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
        $bio = strtolower(trim((string) ($creator['bio'] ?? $sourceRow['bio'] ?? '')));
        $sourceHashtags = array_map('strtolower', array_values(array_filter((array) ($creator['sourceHashtags'] ?? []))));
        $nicheHints = array_map('strtolower', array_values(array_filter((array) ($creator['nicheHints'] ?? []))));
        $briefKeywords = array_map('strtolower', array_values(array_filter(array_merge(
            (array) ($criteria['hashtags'] ?? []),
            (array) ($criteria['nicheKeywords'] ?? []),
            (array) ($criteria['mustHaveSignals'] ?? []),
        ))));
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
            $engagement >= 8 => 24,
            $engagement >= 5 => 20,
            $engagement >= 3 => 14,
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
            $contentQuantity >= 24 => 14,
            $contentQuantity >= 12 => 11,
            $contentQuantity >= 6 => 7,
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

        $verificationScore = $isVerified ? 4 : 0;
        if ($isVerified) {
            $signals[] = 'Verified account adds some trust.';
        }

        $nicheScore = 0;
        $nicheUniverse = array_values(array_unique(array_filter(array_merge($sourceHashtags, $nicheHints, $briefKeywords))));
        if ($bio !== '' && $nicheUniverse !== []) {
            $matches = 0;
            foreach ($nicheUniverse as $tag) {
                if ($tag !== '' && str_contains($bio, $tag)) {
                    $matches++;
                }
            }
            $nicheScore = match (true) {
                $matches >= 2 => 8,
                $matches === 1 => 5,
                default => 0,
            };
            if ($nicheScore >= 8) {
                $signals[] = 'Bio strongly reflects the campaign niche.';
            } elseif ($nicheScore > 0) {
                $signals[] = 'Bio shows some niche alignment.';
            }
        }

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

        $score = match (true) {
            $followers < 1000 => 8,
            $followers < 5000 => 18,
            $followers < 25000 => 28,
            $followers < 100000 => 24,
            $followers < 500000 => 16,
            default => 10,
        };

        $signal = $followers >= 1000 && $followers < 25000
            ? 'Follower range fits typical micro-influencer outreach.'
            : null;

        return [$score, $signal, null];
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
