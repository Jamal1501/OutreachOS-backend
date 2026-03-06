<?php

namespace App\Http\Controllers;

use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ApifyController extends Controller
{
    private const IMPORTABLE_SHEETS = [
        'TikTok_Posts_Raw',
        'Instagram_Posts_Raw',
        'IG_Profile_URL_Queue',
        'TikTok_Profile_URL_Queue',
        'Profile_URL_Queue_All',
        'Instagram_Profile_Enriched',
        'TikTok_Profile_Enriched',
    ];

    public function runTikTokHashtagActor(Request $request)
    {
        $token = env('APIFY_API_TOKEN');
        $actorId = env('APIFY_TIKTOK_HASHTAG_ACTOR_ID');

        if (!$token || !$actorId) {
            return response()->json([
                'error' => 'Missing Apify env vars',
            ], 500);
        }

        $input = $request->all();

        $response = Http::withToken($token)
            ->post("https://api.apify.com/v2/acts/{$actorId}/runs", $input);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Apify run failed',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], 500);
        }

        return response()->json([
            'message' => 'Actor started',
            'apify' => $response->json(),
        ]);
    }

    public function getRunStatus($runId)
    {
        $token = env('APIFY_API_TOKEN');

        if (!$token) {
            return response()->json([
                'error' => 'Missing APIFY_API_TOKEN',
            ], 500);
        }

        $response = Http::withToken($token)
            ->get("https://api.apify.com/v2/actor-runs/{$runId}");

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to fetch run status',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], 500);
        }

        return response()->json([
            'message' => 'Run status fetched',
            'apify' => $response->json(),
        ]);
    }

    public function getDatasetResults(Request $request, $datasetId)
    {
        $token = env('APIFY_API_TOKEN');

        if (!$token) {
            return response()->json([
                'error' => 'Missing APIFY_API_TOKEN',
            ], 500);
        }

        $limit = (int) $request->query('limit', 100);
        $offset = (int) $request->query('offset', 0);

        $response = Http::withToken($token)
            ->get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
                'clean' => 'true',
                'format' => 'json',
                'limit' => $limit,
                'offset' => $offset,
            ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to fetch dataset results',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], 500);
        }

        $items = $response->json();

        return response()->json([
            'message' => 'Dataset results fetched',
            'datasetId' => $datasetId,
            'count' => is_array($items) ? count($items) : 0,
            'items' => $items,
        ]);
    }

    public function importDatasetToSheet(Request $request)
    {
        $token = env('APIFY_API_TOKEN');
        $sheetId = $request->input('sheetId') ?: env('GOOGLE_SHEET_ID');
        $serviceAccountJson = env('GOOGLE_SERVICE_ACCOUNT_JSON');

        if (!$token || !$sheetId || !$serviceAccountJson) {
            return response()->json([
                'error' => 'Missing required env variables',
            ], 500);
        }

        $validated = $request->validate([
            'datasetId' => 'required|string',
            'sheetName' => 'required|string|in:' . implode(',', self::IMPORTABLE_SHEETS),
            'sheetId' => 'nullable|string',
            'platform' => 'nullable|string|in:instagram,tiktok',
            'sourceNotes' => 'nullable|string',
        ]);

        $datasetId = $validated['datasetId'];
        $sheetName = $validated['sheetName'];

        $response = Http::withToken($token)
            ->get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
                'clean' => 'true',
                'format' => 'json',
            ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to fetch dataset results',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], 500);
        }

        $items = $response->json();

        if (!is_array($items) || count($items) === 0) {
            return response()->json([
                'message' => 'No items found in dataset',
                'datasetId' => $datasetId,
                'sheetName' => $sheetName,
            ]);
        }

        $rows = $this->mapRowsForSheet($sheetName, $items, $request);

        if (count($rows) === 0) {
            return response()->json([
                'message' => 'No mappable rows found for target sheet',
                'datasetId' => $datasetId,
                'sheetName' => $sheetName,
            ], 422);
        }

        $client = new GoogleClient();
        $client->setAuthConfig(json_decode($serviceAccountJson, true));
        $client->addScope(Sheets::SPREADSHEETS);

        $sheets = new Sheets($client);

        $body = new ValueRange([
            'values' => $rows,
        ]);

        $params = [
            'valueInputOption' => 'RAW',
        ];

        $sheets->spreadsheets_values->append(
            $sheetId,
            "{$sheetName}!A1",
            $body,
            $params
        );

        return response()->json([
            'message' => 'Dataset imported to Google Sheet',
            'datasetId' => $datasetId,
            'sheetId' => $sheetId,
            'sheetName' => $sheetName,
            'importedRows' => count($rows),
        ]);
    }

    private function mapRowsForSheet(string $sheetName, array $items, Request $request): array
    {
        $rows = [];

        foreach ($items as $item) {
            $row = match ($sheetName) {
                'TikTok_Posts_Raw' => $this->mapTikTokPostRawRow($item),
                'Instagram_Posts_Raw' => $this->mapInstagramPostRawRow($item),
                'IG_Profile_URL_Queue' => $this->mapProfileQueueRow($item, 'instagram', $request),
                'TikTok_Profile_URL_Queue' => $this->mapProfileQueueRow($item, 'tiktok', $request),
                'Profile_URL_Queue_All' => $this->mapProfileQueueAllRow($item, $request),
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
            data_get($item, 'authorMeta.name', data_get($item, 'author.username', '')),
            data_get($item, 'text', data_get($item, 'desc', '')),
            data_get($item, 'diggCount', data_get($item, 'stats.diggCount', '')),
            data_get($item, 'commentCount', data_get($item, 'stats.commentCount', '')),
            data_get($item, 'shareCount', data_get($item, 'stats.shareCount', '')),
            data_get($item, 'collectCount', data_get($item, 'stats.collectCount', '')),
            data_get($item, 'playCount', data_get($item, 'stats.playCount', '')),
            data_get($item, 'createTimeISO', data_get($item, 'createTime', '')),
            data_get($item, 'webVideoUrl', data_get($item, 'url', '')),
        ];
    }

    private function mapInstagramPostRawRow(array $item): array
    {
        return [
            data_get($item, 'caption', ''),
            data_get($item, 'ownerFullName', data_get($item, 'owner.fullName', '')),
            data_get($item, 'ownerUsername', data_get($item, 'owner.username', data_get($item, 'username', ''))),
            data_get($item, 'url', data_get($item, 'postUrl', '')),
            data_get($item, 'commentsCount', data_get($item, 'comments_count', '')),
            data_get($item, 'likesCount', data_get($item, 'likes_count', '')),
            data_get($item, 'timestamp', data_get($item, 'takenAtTimestamp', '')),
            $this->toCsv(data_get($item, 'hashtags', [])),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];
    }

    private function mapProfileQueueRow(array $item, string $platform, Request $request): ?array
    {
        $username = $this->extractUsername($item);
        $handle = $this->normalizeHandle(data_get($item, 'handle', $username));
        $url = $this->extractProfileUrl($item, $platform, $username);

        if (!$username && !$url) {
            return null;
        }

        return [
            $platform,
            $handle,
            $url,
            $username,
            data_get($item, 'name', data_get($item, 'fullName', data_get($item, 'ownerFullName', data_get($item, 'authorMeta.nickName', '')))),
            data_get($item, 'country', data_get($item, 'country_guess', '')),
            data_get($item, 'city', data_get($item, 'city_guess', '')),
            data_get($item, 'primary_language', data_get($item, 'language', data_get($item, 'Primary_Language_Guess', ''))),
            data_get($item, 'niche_category', data_get($item, 'Post_Niche', '')),
            data_get($item, 'status', 'pending'),
            data_get($item, 'priority_for_enrichment', 'normal'),
            $request->input('sourceNotes', ''),
        ];
    }

    private function mapProfileQueueAllRow(array $item, Request $request): ?array
    {
        $platform = $request->input('platform');

        if (!$platform) {
            $url = (string) data_get($item, 'url', data_get($item, 'profile_url', data_get($item, 'input_url', '')));

            if (str_contains($url, 'instagram.com')) {
                $platform = 'instagram';
            } elseif (str_contains($url, 'tiktok.com')) {
                $platform = 'tiktok';
            }
        }

        if (!$platform) {
            return null;
        }

        return $this->mapProfileQueueRow($item, $platform, $request);
    }

    private function mapInstagramProfileEnrichedRow(array $item): array
    {
        return [
            data_get($item, 'username', data_get($item, 'ownerUsername', '')),
            $this->normalizeHandle(data_get($item, 'handle', data_get($item, 'username', data_get($item, 'ownerUsername', '')))),
            $this->extractProfileUrl($item, 'instagram', data_get($item, 'username', data_get($item, 'ownerUsername', ''))),
            data_get($item, 'input_url', data_get($item, 'url', '')),
            data_get($item, 'fullName', data_get($item, 'full_name', '')),
            data_get($item, 'biography', data_get($item, 'bio', '')),
            data_get($item, 'email_from_bio', $this->extractEmailFromText(data_get($item, 'biography', data_get($item, 'bio', '')))),
            data_get($item, 'externalUrl', data_get($item, 'external_url', '')),
            data_get($item, 'followersCount', data_get($item, 'followers', '')),
            data_get($item, 'followsCount', data_get($item, 'following', '')),
            data_get($item, 'postsCount', data_get($item, 'posts_count', '')),
            data_get($item, 'isBusinessAccount', data_get($item, 'is_business_account', '')),
            data_get($item, 'businessCategoryName', data_get($item, 'business_category_name', '')),
            data_get($item, 'private', data_get($item, 'is_private', '')),
            data_get($item, 'verified', data_get($item, 'is_verified', '')),
            data_get($item, 'highlightReelCount', data_get($item, 'highlight_reel_count', '')),
            data_get($item, 'igtvVideoCount', data_get($item, 'igtv_video_count', '')),
            data_get($item, 'recent_est_engagement_rate_pct', ''),
            data_get($item, 'recent_avg_likes', ''),
            data_get($item, 'recent_avg_comments', ''),
            data_get($item, 'recent_posts_used_for_engagement', ''),
            data_get($item, 'latestPosts_count', data_get($item, 'latest_posts_count', '')),
            data_get($item, 'apify_profile_id', data_get($item, 'id', '')),
        ];
    }

    private function mapTikTokProfileEnrichedRow(array $item): array
    {
        $bio = data_get($item, 'bio', data_get($item, 'signature', ''));

        return [
            data_get($item, 'username', data_get($item, 'authorMeta.name', '')),
            $this->normalizeHandle(data_get($item, 'handle', data_get($item, 'username', data_get($item, 'authorMeta.name', '')))),
            $this->extractProfileUrl($item, 'tiktok', data_get($item, 'username', data_get($item, 'authorMeta.name', ''))),
            data_get($item, 'input_url', data_get($item, 'url', '')),
            data_get($item, 'nickname', data_get($item, 'authorMeta.nickName', '')),
            $bio,
            data_get($item, 'email_from_bio', $this->extractEmailFromText($bio)),
            data_get($item, 'externalUrl', data_get($item, 'bioLink.link', '')),
            data_get($item, 'followersCount', data_get($item, 'authorStats.followerCount', data_get($item, 'followers', ''))),
            data_get($item, 'followingCount', data_get($item, 'authorStats.followingCount', data_get($item, 'following', ''))),
            data_get($item, 'likesCount', data_get($item, 'authorStats.heartCount', data_get($item, 'likes', ''))),
            data_get($item, 'videoCount', data_get($item, 'authorStats.videoCount', data_get($item, 'posts', ''))),
            data_get($item, 'verified', data_get($item, 'authorMeta.verified', data_get($item, 'isVerified', ''))),
            data_get($item, 'private', data_get($item, 'privateAccount', data_get($item, 'isPrivate', ''))),
            data_get($item, 'region', ''),
            data_get($item, 'language', ''),
            data_get($item, 'recent_avg_views', ''),
            data_get($item, 'recent_avg_likes', ''),
            data_get($item, 'recent_avg_comments', ''),
            data_get($item, 'recent_posts_used', ''),
            data_get($item, 'latestPosts_count', data_get($item, 'latest_posts_count', '')),
            data_get($item, 'apify_profile_id', data_get($item, 'id', '')),
            now()->toDateTimeString(),
        ];
    }

    private function extractUsername(array $item): string
    {
        return (string) (
            data_get($item, 'username')
            ?? data_get($item, 'ownerUsername')
            ?? data_get($item, 'owner.username')
            ?? data_get($item, 'authorMeta.name')
            ?? data_get($item, 'author.username')
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
            data_get($item, 'profile_url'),
            data_get($item, 'profileUrl'),
            data_get($item, 'input_url'),
            data_get($item, 'url'),
            data_get($item, 'authorMeta.profileUrl'),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate === '') {
                continue;
            }

            if ($platform === 'instagram' && str_contains($candidate, 'instagram.com')) {
                return $candidate;
            }

            if ($platform === 'tiktok' && str_contains($candidate, 'tiktok.com')) {
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
