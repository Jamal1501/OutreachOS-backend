<?php

namespace Tests\Unit;

use App\Services\CreatorLifecycleService;
use App\Services\GoogleSheetsService;
use App\Services\InfluencerScoringService;
use App\Services\OperatorViewService;
use App\Services\ProjectResolverService;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class OperatorViewNextBestActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reply_signal_becomes_next_action(): void
    {
        Carbon::setTestNow('2026-06-27 12:00:00');

        $action = $this->invokeNextBestAction(
            ['lifecycleState' => 'replied', 'email' => 'creator@example.com', 'valueScore' => 72, 'followers' => 12000, 'engagementRate' => 3.4],
            [
                ['type' => 'DM_FOLLOWUP', 'status' => 'pending', 'dueDate' => '2026-06-27 09:00:00'],
            ],
            [
                ['type' => 'creator_reply', 'timestamp' => '2026-06-27 10:30:00'],
                ['type' => 'outreach_sent', 'timestamp' => '2026-06-24 10:00:00'],
            ]
        );

        $this->assertSame('draft_reply', $action['actionKey']);
        $this->assertSame('outreach_reply', $action['route']);
        $this->assertSame('urgent', $action['priority']);
    }

    public function test_overdue_follow_up_wins_over_warm_up_task(): void
    {
        Carbon::setTestNow('2026-06-27 12:00:00');

        $action = $this->invokeNextBestAction(
            ['lifecycleState' => 'contacted', 'email' => '', 'valueScore' => 64, 'followers' => 8500, 'engagementRate' => 2.1],
            [
                ['type' => 'FOLLOW_REQUEST', 'status' => 'pending', 'dueDate' => '2026-06-27 09:00:00'],
            ],
            [
                ['type' => 'outreach_sent', 'timestamp' => '2026-06-23 10:00:00'],
            ]
        );

        $this->assertSame('send_follow_up', $action['actionKey']);
        $this->assertSame('outreach', $action['route']);
        $this->assertSame('high', $action['priority']);
    }

    public function test_duplicate_review_blocks_new_outreach(): void
    {
        Carbon::setTestNow('2026-06-27 12:00:00');

        $action = $this->invokeNextBestAction(
            ['lifecycleState' => 'duplicate_review_needed', 'email' => 'creator@example.com', 'valueScore' => 80, 'followers' => 24000, 'engagementRate' => 4.2],
            [],
            [],
            ['Potential duplicate profile']
        );

        $this->assertSame('resolve_duplicate_risk', $action['actionKey']);
        $this->assertSame('duplicates', $action['route']);
        $this->assertSame('urgent', $action['priority']);
    }

    public function test_new_email_creator_gets_first_email_action(): void
    {
        Carbon::setTestNow('2026-06-27 12:00:00');

        $action = $this->invokeNextBestAction(
            ['lifecycleState' => 'enriched', 'email' => 'creator@example.com', 'valueScore' => 68, 'followers' => 15000, 'engagementRate' => 3.1]
        );

        $this->assertSame('send_first_email', $action['actionKey']);
        $this->assertSame('outreach', $action['route']);
        $this->assertSame('high', $action['priority']);
    }

    private function invokeNextBestAction(array $creator, array $relatedTasks = [], array $timeline = [], array $hardDisqualifiers = []): array
    {
        $service = new OperatorViewService(
            $this->createMock(GoogleSheetsService::class),
            $this->createMock(InfluencerScoringService::class),
            $this->createMock(CreatorLifecycleService::class),
            $this->createMock(ProjectResolverService::class)
        );

        $method = new ReflectionMethod(OperatorViewService::class, 'nextBestAction');
        $method->setAccessible(true);

        return $method->invoke($service, $creator, $relatedTasks, $timeline, $hardDisqualifiers);
    }
}
