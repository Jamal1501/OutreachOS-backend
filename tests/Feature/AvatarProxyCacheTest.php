<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarProxyCacheTest extends TestCase
{
    public function test_avatar_proxy_caches_successful_upstream_images(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://scontent-lax3-1.cdninstagram.com/*' => Http::response('fake-image', 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => '10',
            ]),
        ]);

        $url = 'https://scontent-lax3-1.cdninstagram.com/avatar.jpg?token=temporary';
        $hash = hash('sha256', $url);

        $this->get('/api/avatar-proxy?url=' . urlencode($url))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        Storage::disk('local')->assertExists("avatar-cache/{$hash}.bin");
        Storage::disk('local')->assertExists("avatar-cache/{$hash}.json");
    }

    public function test_avatar_proxy_serves_cached_image_without_rechecking_expired_upstream_url(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://scontent-lax3-1.cdninstagram.com/*' => Http::response('', 403),
        ]);

        $url = 'https://scontent-lax3-1.cdninstagram.com/avatar.jpg?token=temporary';
        $hash = hash('sha256', $url);

        Storage::disk('local')->put("avatar-cache/{$hash}.bin", 'cached-image');
        Storage::disk('local')->put("avatar-cache/{$hash}.json", json_encode([
            'contentType' => 'image/jpeg',
            'cachedAt' => now()->subDay()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        $this->get('/api/avatar-proxy?url=' . urlencode($url))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertSee('cached-image');

        Http::assertNothingSent();
    }
}
