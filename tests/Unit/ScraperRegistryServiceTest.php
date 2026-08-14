<?php

namespace Tests\Unit;

use App\Services\ScraperRegistryService;
use RuntimeException;
use Tests\TestCase;

class ScraperRegistryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.apify.actors.instagram_discovery', 'actor-instagram-discovery');
        config()->set('services.apify.actors.instagram_profile', 'actor-instagram-profile');
        config()->set('services.apify.actors.instagram_content_deep', 'actor-instagram-deep');
        config()->set('services.apify.actors.tiktok_discovery', 'actor-tiktok-discovery');
        config()->set('services.apify.actors.tiktok_profile', 'actor-tiktok-profile');
        config()->set('services.apify.actors.tiktok_comments_deep', 'actor-tiktok-deep');
    }

    public function test_standard_profile_modules_expose_the_crm_fields_the_pipeline_depends_on(): void
    {
        $registry = app(ScraperRegistryService::class);

        $instagram = $registry->resolvePipelineModule('pro', 'instagram', 'enrichment');
        $tiktok = $registry->resolvePipelineModule('pro', 'tiktok', 'enrichment');

        $this->assertSame('instagram.profile.standard', $instagram['key']);
        $this->assertSame('tiktok.profile.standard', $tiktok['key']);
        $this->assertEmpty(array_diff(['bio', 'followersCount', 'latestPosts'], $instagram['fields']));
        $this->assertEmpty(array_diff(['bio', 'followersCount', 'latestPosts'], $tiktok['fields']));
    }

    public function test_unknown_depth_cannot_outrank_a_known_safe_module(): void
    {
        $modules = config('scrapers.modules');
        $modules['instagram.profile.unknown'] = [
            'key' => 'instagram.profile.unknown',
            'label' => 'Unknown',
            'platform' => 'instagram',
            'stage' => 'enrichment',
            'depth' => 'experimental',
            'cost_class' => 'profile_standard',
            'actor_key' => 'instagram_unknown',
            'allowed_plans' => ['pro'],
            'fields' => ['bio'],
        ];
        config()->set('scrapers.modules', $modules);
        config()->set('services.apify.actors.instagram_unknown', 'actor-unknown');
        config()->set('scrapers.defaults.instagram.enrichment', '');

        $module = app(ScraperRegistryService::class)->defaultModuleForPlan('pro', 'instagram', 'enrichment');

        $this->assertSame('instagram.profile.standard', $module['key']);
    }

    public function test_system_default_cannot_bypass_plan_allowlist(): void
    {
        config()->set('scrapers.defaults.instagram.enrichment', 'instagram.content.deep');
        $modules = config('scrapers.modules');
        $modules['instagram.profile.standard']['allowed_plans'] = ['enterprise'];
        config()->set('scrapers.modules', $modules);

        $this->expectException(RuntimeException::class);
        app(ScraperRegistryService::class)->resolvePipelineModule('pro', 'instagram', 'enrichment');
    }
}
