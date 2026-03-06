<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ApifyRowMapper
{
    public const IMPORTABLE_SHEETS = [
        'TikTok_Posts_Raw',
        'Instagram_Posts_Raw',
        'IG_Profile_URL_Queue',
        'TikTok_Profile_URL_Queue',
        'Profile_URL_Queue_All',
        'Instagram_Profile_Enriched',
        'TikTok_Profile_Enriched',
    ];

    public function mapRowsForSheet(string $sheetName, array $items, array $context = []): array
    {
        $rows = [];

        foreach ($items as $item) {
            $row = match ($sheetName) {
                'TikTok_Posts_Raw' => $this->mapTikTokPostRawRow($item),
                'Instagram_Posts_Raw' => $this->mapInstagramPostRawRow($item),
                'IG_Profile_URL_Queue' => $this->mapProfileQueueRow($item, 'instagram', $context),
                'TikTok_Profile_URL_Queue' => $this->mapProfileQueueRow($item, 'tiktok', $context),
                'Profile_URL_Queue_All' => $this->mapProfileQueueAllRow($item, $context),
                'Instagram_Profile_Enriched' => $this->mapInstagramProfileEnrichedRow($item),
                'TikTok_Profile_Enriched' => $this->mapTikTokProfileEnrichedRow($item),
                default => null,
            };

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $this->dedupeRows($rows);
    }

    private function mapTikTokPostRawRow(array $item): array
    {
        return [
            Arr::get($item, 'authorMeta.name', Arr::get($item, 'author.username', '')),
            Arr::get($item, 'text', Arr::get($item, 'desc', '')),
            Arr::get($item, 'diggCount', Arr::get($item, 'stats.diggCount', '')),
            Arr::get($item, 'commentCount', Arr::get($item, 'stats.commentCount', '')),
            Arr::get($item, 'shareCount', Arr::get($item, 'stats.shareCount', '')),
            Arr::get($item, 'collectCount', Arr::get($item, 'stats.collectCount', '')),
            Arr::get($item, 'playCount', Arr::get($item, 'stats.playCount', '')),
            Arr::get($item, 'createTimeISO', Arr::get($item, 'createTime', '')),
            Arr::get($item, 'webVideoUrl', Arr::get($item, 'url', '')),
        ];
    }

    private function mapInstagramPostRawRow(array $item): array
    {
        return [
            Arr::get($item, 'caption', ''),
            Arr::get($item, 'ownerFullName', Arr::get($item, 'owner.fullName', '')),
            Arr::get($item, 'ownerUsername', Arr::get($item, 'owner.username', Arr::get($item, 'username', ''))),
            Arr::get($item, 'url', Arr::get($item, 'postUrl', '')),
            Arr::get($item, 'commentsCount', Arr::get($item, 'comments_count', '')),
            Arr::get($item, 'likesCount', Arr::get($item, 'likes_count', '')),
            Arr::get($item, 'timestamp', Arr::get($item, 'takenAtTimestamp', '')),
            $this->toCsv(Arr::get($item, 'hashtags', [])),
            '', '', '', '', '', '', '', '', '', '', '',
        ];
    }

    private function mapProfileQueueRow(array $item, string $platform, array $context): ?array
    {
        $username = $this->extractUsername($item);
        $handle = $this->normalizeHandle(Arr::get($item, 'handle', $username));
        $url = $this->extractProfileUrl($item, $platform, $username);

        if ($username === '' && $url === '') {
            return null;
        }

        return [
            $platform,
            $handle,
            $url,
            $username,
            Arr::get($item, 'name', Arr::get($item, 'fullName', Arr::get($item, 'ownerFullName', Arr::get($item, 'authorMeta.nickName', '')))),
            Arr::get($item, 'country', Arr::get($item, 'country_guess', '')),
            Arr::get($item, 'city', Arr::get($item, 'city_guess', '')),
            Arr::get($item, 'primary_language', Arr::get($item, 'language', Arr::get($item, 'Primary_Language_Guess', ''))),
            Arr::get($item, 'niche_category', Arr::get($item, 'Post_Niche', '')),
            Arr::get($item, 'status', 'pending'),
            Arr::get($item, 'priority_for_enrichment', 'normal'),
            (string) ($context['sourceNotes'] ?? ''),
        ];
    }

    private function mapProfileQueueAllRow(array $item, array $context): ?array
    {
        $platform = $context['platform'] ?? null;

        if (!$platform) {
            $url = (string) Arr::get($item, 'url', Arr::get($item, 'profile_url', Arr::get($item, 'input_url', '')));
            $platform = Str::contains($url, 'instagram.com') ? 'instagram' : (Str::contains($url, 'tiktok.com') ? 'tiktok' : null);
        }

        return $platform ? $this->mapProfileQueueRow($item, $platform, $context) : null;
    }

    private function mapInstagramProfileEnrichedRow(array $item): array
    {
        return [
            Arr::get($item, 'username', Arr::get($item, 'ownerUsername', '')),
            $this->normalizeHandle(Arr::get($item, 'handle', Arr::get($item, 'username', Arr::get($item, 'ownerUsername', '')))),
            $this->extractProfileUrl($item, 'instagram', Arr::get($item, 'username', Arr::get($item, 'ownerUsername', ''))),
            Arr::get($item, 'inputUrl', Arr::get($item, 'input_url', Arr::get($item, 'url', ''))),
            Arr::get($item, 'fullName', Arr::get($item, 'full_name', '')),
            Arr::get($item, 'biography', Arr::get($item, 'bio', '')),
            Arr::get($item, 'email_from_bio', $this->extractEmailFromText(Arr::get($item, 'biography', Arr::get($item, 'bio', '')))),
            Arr::get($item, 'externalUrl', Arr::get($item, 'external_url', '')),
            Arr::get($item, 'followersCount', Arr::get($item, 'followers', '')),
            Arr::get($item, 'followsCount', Arr::get($item, 'following', '')),
            Arr::get($item, 'postsCount', Arr::get($item, 'posts_count', '')),
            $this->boolString(Arr::get($item, 'isBusinessAccount', Arr::get($item, 'is_business_account', ''))),
            Arr::get($item, 'businessCategoryName', Arr::get($item, 'business_category_name', '')),
            $this->boolString(Arr::get($item, 'private', Arr::get($item, 'is_private', ''))),
            $this->boolString(Arr::get($item, 'verified', Arr::get($item, 'is_verified', ''))),
            Arr::get($item, 'highlightReelCount', Arr::get($item, 'highlight_reel_count', '')),
            Arr::get($item, 'igtvVideoCount', Arr::get($item, 'igtv_video_count', '')),
            $this->estimateInstagramEngagementRate($item),
            $this->averageFromLatestPosts($item, 'likesCount'),
            $this->averageFromLatestPosts($item, 'commentsCount'),
            count(Arr::get($item, 'latestPosts', [])),
            count(Arr::get($item, 'latestPosts', [])),
            Arr::get($item, 'apify_profile_id', Arr::get($item, 'id', '')),
        ];
    }

    private function mapTikTokProfileEnrichedRow(array $item): array
    {
        $bio = Arr::get($item, 'bio', Arr::get($item, 'signature', ''));
        $latestPosts = Arr::get($item, 'latestPosts', Arr::get($item, 'latest_posts', []));

        return [
            Arr::get($item, 'username', Arr::get($item, 'authorMeta.name', '')),
            $this->normalizeHandle(Arr::get($item, 'handle', Arr::get($item, 'username', Arr::get($item, 'authorMeta.name', '')))),
            $this->extractProfileUrl($item, 'tiktok', Arr::get($item, 'username', Arr::get($item, 'authorMeta.name', ''))),
            Arr::get($item, 'inputUrl', Arr::get($item, 'input_url', Arr::get($item, 'url', ''))),
            Arr::get($item, 'nickname', Arr::get($item, 'authorMeta.nickName', '')),
            $bio,
            Arr::get($item, 'email_from_bio', $this->extractEmailFromText($bio)),
            Arr::get($item, 'externalUrl', Arr::get($item, 'bioLink.link', '')),
            Arr::get($item, 'followersCount', Arr::get($item, 'authorStats.followerCount', Arr::get($item, 'followers', ''))),
            Arr::get($item, 'followingCount', Arr::get($item, 'authorStats.followingCount', Arr::get($item, 'following', ''))),
            Arr::get($item, 'likesCount', Arr::get($item, 'authorStats.heartCount', Arr::get($item, 'likes', ''))),
            Arr::get($item, 'videoCount', Arr::get($item, 'authorStats.videoCount', Arr::get($item, 'posts', ''))),
            $this->boolString(Arr::get($item, 'verified', Arr::get($item, 'authorMeta.verified', Arr::get($item, 'isVerified', '')))),
            $this->boolString(Arr::get($item, 'private', Arr::get($item, 'privateAccount', Arr::get($item, 'isPrivate', '')))),
            Arr::get($item, 'region', ''),
            Arr::get($item, 'language', ''),
            $this->averageFromLatestPosts($latestPosts, 'playCount'),
            $this->averageFromLatestPosts($latestPosts, 'diggCount'),
            $this->averageFromLatestPosts($latestPosts, 'commentCount'),
            count($latestPosts),
            count($latestPosts),
            Arr::get($item, 'apify_profile_id', Arr::get($item, 'id', '')),
            now()->toDateTimeString(),
        ];
    }

    private function averageFromLatestPosts(array $itemOrPosts, string $metric): string
    {
        $posts = array_is_list($itemOrPosts) ? $itemOrPosts : Arr::get($itemOrPosts, 'latestPosts', Arr::get($itemOrPosts, 'latest_posts', []));

        if (!is_array($posts) || count($posts) === 0) {
            return '';
        }

        $values = [];

        foreach ($posts as $post) {
            $value = Arr::get($post, $metric, Arr::get($post, 'stats.' . $metric));
            if (is_numeric($value)) {
                $values[] = (float) $value;
            }
        }

        if (count($values) === 0) {
            return '';
        }

        return (string) round(array_sum($values) / count($values), 2);
    }

    private function estimateInstagramEngagementRate(array $item): string
    {
        $followers = (float) Arr::get($item, 'followersCount', Arr::get($item, 'followers', 0));
        if ($followers <= 0) {
            return '';
        }

        $avgLikes = (float) $this->averageFromLatestPosts($item, 'likesCount');
        $avgComments = (float) $this->averageFromLatestPosts($item, 'commentsCount');
        $engagement = (($avgLikes + $avgComments) / $followers) * 100;

        return $engagement > 0 ? (string) round($engagement, 2) : '';
    }

    private function extractUsername(array $item): string
    {
        return (string) (
            Arr::get($item, 'username')
            ?? Arr::get($item, 'ownerUsername')
            ?? Arr::get($item, 'owner.username')
            ?? Arr::get($item, 'authorMeta.name')
            ?? Arr::get($item, 'author.username')
            ?? ''
        );
    }

    private function normalizeHandle(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return str_starts_with($value, '@') ? $value : '@' . $value;
    }

    private function extractProfileUrl(array $item, string $platform, ?string $username = null): string
    {
        $candidates = [
            Arr::get($item, 'profile_url'),
            Arr::get($item, 'profileUrl'),
            Arr::get($item, 'inputUrl'),
            Arr::get($item, 'input_url'),
            Arr::get($item, 'url'),
            Arr::get($item, 'authorMeta.profileUrl'),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if ($platform === 'instagram' && Str::contains($candidate, 'instagram.com')) {
                return $candidate;
            }

            if ($platform === 'tiktok' && Str::contains($candidate, 'tiktok.com')) {
                return $candidate;
            }
        }

        $username = trim((string) $username);
        if ($username === '') {
            return '';
        }

        return $platform === 'instagram'
            ? "https://www.instagram.com/{$username}/"
            : "https://www.tiktok.com/@{$username}";
    }

    private function extractEmailFromText(?string $text): string
    {
        $text = (string) $text;

        if ($text === '') {
            return '';
        }

        preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $matches);

        return $matches[0] ?? '';
    }

    private function toCsv($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => (string) $item, $value));
        }

        return (string) $value;
    }

    private function boolString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        return (string) $value;
    }

    private function dedupeRows(array $rows): array
    {
        $unique = [];
        $seen = [];

        foreach ($rows as $row) {
            $key = md5(json_encode($row));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $row;
        }

        return $unique;
    }
}
