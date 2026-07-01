<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AvatarCacheService
{
    private const MAX_AVATAR_BYTES = 2097152;

    public function responseForUrl(string $url)
    {
        $validation = $this->validateUrl($url);
        if (isset($validation['response'])) {
            return $validation['response'];
        }

        $url = $validation['url'];
        $host = $validation['host'];
        $cacheKey = $this->cacheKey($url);
        $avatarPath = $this->avatarPath($cacheKey);
        $metaPath = $this->metaPath($cacheKey);

        $cachedAvatar = $this->readCachedAvatar($avatarPath, $metaPath);
        if ($cachedAvatar !== null) {
            return $this->avatarResponse($cachedAvatar['body'], $cachedAvatar['contentType'], 604800, 'hit');
        }

        $fetchedAvatar = $this->fetchAvatar($url, $host, $cacheKey);
        if ($fetchedAvatar === null) {
            return response()->json([
                'message' => 'Avatar fetch failed',
            ], 502);
        }

        $this->writeCachedAvatar($avatarPath, $metaPath, $fetchedAvatar['body'], $fetchedAvatar['contentType']);

        return $this->avatarResponse($fetchedAvatar['body'], $fetchedAvatar['contentType'], 604800, 'miss');
    }

    public function warmManyAfterResponse(array $urls, int $limit = 20): void
    {
        $urls = array_values(array_unique(array_filter(array_map(
            fn ($url) => trim((string) $url),
            $urls,
        ), fn ($url) => $url !== '')));

        if ($urls === []) {
            return;
        }

        $urls = array_slice($urls, 0, max(1, $limit));

        app()->terminating(function () use ($urls): void {
            $this->warmMany($urls);
        });
    }

    public function warmMany(array $urls): void
    {
        foreach ($urls as $url) {
            $this->warm((string) $url);
        }
    }

    public function warm(string $url): bool
    {
        $validation = $this->validateUrl($url);
        if (isset($validation['response'])) {
            return false;
        }

        $url = $validation['url'];
        $host = $validation['host'];
        $cacheKey = $this->cacheKey($url);
        $avatarPath = $this->avatarPath($cacheKey);
        $metaPath = $this->metaPath($cacheKey);

        if ($this->readCachedAvatar($avatarPath, $metaPath) !== null) {
            return true;
        }

        $fetchedAvatar = $this->fetchAvatar($url, $host, $cacheKey, 5);
        if ($fetchedAvatar === null) {
            return false;
        }

        $this->writeCachedAvatar($avatarPath, $metaPath, $fetchedAvatar['body'], $fetchedAvatar['contentType']);

        return true;
    }

    private function validateUrl(string $url): array
    {
        $url = trim($url);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'response' => response()->json([
                    'message' => 'Invalid avatar URL',
                ], 422),
            ];
        }

        $parts = parse_url($url);
        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return [
                'response' => response()->json([
                    'message' => 'Invalid avatar host',
                ], 422),
            ];
        }

        $allowedHostSuffixes = [
            'cdninstagram.com',
            'fbcdn.net',
            'instagram.com',
            'tiktokcdn.com',
            'muscdn.com',
            'byteoversea.com',
            'ibyteimg.com',
        ];

        foreach ($allowedHostSuffixes as $suffix) {
            if ($host === $suffix || Str::endsWith($host, '.' . $suffix)) {
                return [
                    'url' => $url,
                    'host' => $host,
                ];
            }
        }

        return [
            'response' => response()->json([
                'message' => 'Avatar host not allowed',
            ], 403),
        ];
    }

    private function fetchAvatar(string $url, string $host, string $cacheKey, int $timeoutSeconds = 12): ?array
    {
        try {
            $upstream = Http::timeout($timeoutSeconds)
                ->withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('avatar proxy fetch failed', [
                'host' => $host,
                'url_hash' => $cacheKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($upstream->redirect()) {
            return null;
        }

        if (!$upstream->ok()) {
            Log::warning('avatar proxy upstream not ok', [
                'host' => $host,
                'url_hash' => $cacheKey,
                'status' => $upstream->status(),
            ]);

            return null;
        }

        $contentType = (string) $upstream->header('Content-Type', '');
        $contentLength = (int) $upstream->header('Content-Length', 0);
        $body = $upstream->body();

        if ($contentLength > self::MAX_AVATAR_BYTES || strlen($body) > self::MAX_AVATAR_BYTES) {
            return null;
        }

        if (!Str::startsWith(Str::lower($contentType), 'image/')) {
            return null;
        }

        return [
            'body' => $body,
            'contentType' => $contentType,
        ];
    }

    private function readCachedAvatar(string $avatarPath, string $metaPath): ?array
    {
        $disk = $this->disk();

        if (!$disk->exists($avatarPath) || !$disk->exists($metaPath)) {
            return null;
        }

        $meta = json_decode((string) $disk->get($metaPath), true);
        $contentType = is_array($meta) ? (string) ($meta['contentType'] ?? '') : '';

        if (!Str::startsWith(Str::lower($contentType), 'image/')) {
            return null;
        }

        $body = $disk->get($avatarPath);
        if (!is_string($body) || $body === '') {
            return null;
        }

        return [
            'body' => $body,
            'contentType' => $contentType,
        ];
    }

    private function writeCachedAvatar(string $avatarPath, string $metaPath, string $body, string $contentType): void
    {
        try {
            $disk = $this->disk();
            $disk->put($avatarPath, $body);
            $disk->put($metaPath, json_encode([
                'contentType' => $contentType,
                'cachedAt' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            Log::warning('avatar proxy cache write failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function avatarResponse(string $body, string $contentType, int $maxAgeSeconds, string $cacheStatus)
    {
        return response($body, 200)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', "public, max-age={$maxAgeSeconds}")
            ->header('Cross-Origin-Resource-Policy', 'cross-origin')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Avatar-Cache', $cacheStatus);
    }

    private function cacheKey(string $url): string
    {
        return hash('sha256', $url);
    }

    private function avatarPath(string $cacheKey): string
    {
        return "avatar-cache/{$cacheKey}.bin";
    }

    private function metaPath(string $cacheKey): string
    {
        return "avatar-cache/{$cacheKey}.json";
    }

    private function disk()
    {
        return Storage::disk((string) config('filesystems.avatar_cache', 'local'));
    }
}
