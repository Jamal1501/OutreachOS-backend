<?php

namespace App\Services;

use Carbon\Carbon;

class InfluencerScoringService
{
    public function score(array $creator, ?array $sourceRow = null): int
    {
        $followers = $this->toFloat($creator['Followers'] ?? $sourceRow['followersCount'] ?? $sourceRow['Followers'] ?? 0);
        $engagement = $this->resolveEngagementRate($creator, $sourceRow);
        $contentQuantity = $this->toFloat(
            $sourceRow['postsCount']
                ?? $sourceRow['videoCount']
                ?? $sourceRow['latestPosts_count']
                ?? $sourceRow['recent_posts_used_for_engagement']
                ?? $sourceRow['recent_posts_used']
                ?? 0
        );
        $recencyDays = $this->resolveRecencyDays($creator, $sourceRow);

        $followerScore = min(40, log10(max(1.0, $followers) + 1) * 8);
        $engagementScore = min(30, max(0, $engagement) * 4);
        $quantityScore = min(20, sqrt(max(0, $contentQuantity)) * 2.5);
        $recencyScore = match (true) {
            $recencyDays === null => 0,
            $recencyDays <= 14 => 10,
            $recencyDays <= 30 => 7,
            $recencyDays <= 60 => 4,
            default => 1,
        };

        return (int) max(0, min(100, round($followerScore + $engagementScore + $quantityScore + $recencyScore)));
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

    private function resolveEngagementRate(array $creator, ?array $sourceRow = null): float
    {
        $fromCreator = $this->toFloat($creator['Engagement_Rate_%'] ?? 0);
        if ($fromCreator > 0) {
            return $fromCreator;
        }

        $fromIg = $this->toFloat($sourceRow['recent_est_engagement_rate_pct'] ?? 0);
        if ($fromIg > 0) {
            return $fromIg;
        }

        $followers = $this->toFloat($creator['Followers'] ?? $sourceRow['followersCount'] ?? 0);
        $avgLikes = $this->toFloat($sourceRow['recent_avg_likes'] ?? 0);
        $avgComments = $this->toFloat($sourceRow['recent_avg_comments'] ?? 0);

        if ($followers > 0 && ($avgLikes > 0 || $avgComments > 0)) {
            return round((($avgLikes + $avgComments) / $followers) * 100, 2);
        }

        return 0;
    }

    private function resolveRecencyDays(array $creator, ?array $sourceRow = null): ?int
    {
        $candidate = trim((string) ($creator['Last_Content_Date'] ?? ''));

        foreach ([$candidate] as $value) {
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
