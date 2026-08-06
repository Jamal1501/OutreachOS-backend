<?php

namespace Tests\Unit;

use App\Services\CreatorLifecycleService;
use App\Services\InfluencerScoringService;
use App\Services\LegacyWorkbookStore;
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

    public function test_contacted_creator_with_recent_outreach_does_not_show_data_gap(): void
    {
        Carbon::setTestNow('2026-06-27 12:00:00');

        $action = $this->invokeNextBestAction(
            ['lifecycleState' => 'contacted', 'email' => '', 'valueScore' => 70, 'followers' => 9000, 'engagementRate' => 2.8],
            [],
            [
                ['type' => 'dm_sent', 'timestamp' => '2026-06-26 10:40:30'],
            ]
        );

        $this->assertSame('check_conversation', $action['actionKey']);
        $this->assertSame('timing_rule', $action['source']);
        $this->assertSame('outreach', $action['route']);
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

    public function test_outreach_queue_requires_approved_state_and_real_open_outreach_task(): void
    {
        $this->assertTrue($this->invokePrivate('isCreatorInOutreachQueue', [
            ['lifecycleState' => 'approved_for_outreach'],
            [['type' => 'DM_INVITE', 'status' => 'pending']],
        ]));

        $this->assertFalse($this->invokePrivate('isCreatorInOutreachQueue', [
            ['lifecycleState' => 'enriched', 'valueScore' => 90],
            [['type' => 'DM_INVITE', 'status' => 'pending']],
        ]));

        $this->assertFalse($this->invokePrivate('isCreatorInOutreachQueue', [
            ['lifecycleState' => 'queued'],
            [['type' => 'DM_INVITE', 'status' => 'completed']],
        ]));
    }

    public function test_daily_brief_explains_the_outreach_count_in_plain_language(): void
    {
        $brief = $this->invokePrivate('dailyBrief', [[], 0, 2, 0]);

        $this->assertSame('Right now: 2 creators are waiting for their first outreach task to be completed.', $brief);
    }

    public function test_workflow_health_surfaces_bottlenecks_from_full_data(): void
    {
        Carbon::setTestNow('2026-06-27 12:00:00');

        $health = $this->invokePrivate('workflowHealth', [
            [
                ['id' => '1', 'platform' => 'instagram', 'handle' => '@ready', 'lifecycleState' => 'approved_for_outreach', 'email' => '', 'profileUrl' => ''],
                ['id' => '2', 'platform' => 'instagram', 'handle' => '@reply', 'lifecycleState' => 'replied', 'email' => '', 'profileUrl' => 'https://instagram.com/reply'],
                ['id' => '3', 'platform' => 'instagram', 'handle' => '@review', 'lifecycleState' => 'needs_review', 'email' => '', 'profileUrl' => ''],
            ],
            [
                ['id' => 'task-1', 'type' => 'DM_FOLLOWUP', 'status' => 'pending', 'dueDate' => '2026-06-26 09:00:00'],
                ['id' => 'task-2', 'creatorProfileId' => '2', 'type' => 'REVIEW_CREATOR', 'groupType' => 'reply_review', 'status' => 'pending', 'dueDate' => '2026-06-27 10:00:00'],
            ],
            [
                ['key' => 'dup-1', 'risk' => 'high'],
            ],
            [],
            [
                ['id' => '3', 'lifecycleState' => 'needs_review'],
            ],
            [
                ['id' => 'task-1', 'type' => 'DM_FOLLOWUP', 'status' => 'pending', 'dueDate' => '2026-06-26 09:00:00'],
            ],
        ]);

        $this->assertStringStartsWith('Right now:', $health['dailyBrief']);
        $this->assertContains('duplicate_reviews', array_column($health['bottlenecks'], 'key'));
        $this->assertContains('overdue_tasks', array_column($health['bottlenecks'], 'key'));
        $this->assertContains('replies_waiting', array_column($health['bottlenecks'], 'key'));
        $this->assertSame(1, $health['counts']['repliesWaiting']);
        $this->assertSame(1, $health['counts']['qualifiedButNoTask']);
    }

    public function test_replied_lifecycle_without_an_open_reply_review_is_not_counted_as_waiting(): void
    {
        $health = $this->invokePrivate('workflowHealth', [
            [
                ['id' => 'creator-1', 'lifecycleState' => 'replied', 'email' => '', 'profileUrl' => 'https://instagram.com/creator'],
            ],
            [],
            [],
            [],
            [],
            [],
        ]);

        $this->assertSame(0, $health['counts']['repliesWaiting']);
        $this->assertNotContains('replies_waiting', array_column($health['bottlenecks'], 'key'));
    }

    public function test_duplicate_signals_for_the_same_creator_pair_count_as_one_review(): void
    {
        $warnings = $this->invokePrivate('detectDuplicateWarnings', [[
            [
                'id' => 'creator-1',
                'handle' => '@same',
                'email' => 'same@example.com',
                'fullName' => 'Same Creator',
                'platform' => 'instagram',
                'lifecycleState' => 'needs_review',
            ],
            [
                'id' => 'creator-2',
                'handle' => '@same',
                'email' => 'same@example.com',
                'fullName' => 'Same Creator',
                'platform' => 'tiktok',
                'lifecycleState' => 'needs_review',
            ],
        ]]);

        $this->assertCount(1, $warnings);
        $this->assertCount(2, $warnings[0]['creators']);
        $this->assertStringContainsString('Shared handle detected', $warnings[0]['reason']);
        $this->assertStringContainsString('Shared email detected', $warnings[0]['reason']);
    }

    public function test_reviewed_not_duplicate_creator_is_excluded_from_duplicate_warnings(): void
    {
        $warnings = $this->invokePrivate('detectDuplicateWarnings', [[
            [
                'id' => 'creator-1',
                'handle' => '@same',
                'email' => 'same@example.com',
                'fullName' => 'Same Creator',
                'platform' => 'instagram',
                'lifecycleState' => 'enriched',
                'duplicateReviewOutcome' => 'not_duplicate',
            ],
            [
                'id' => 'creator-2',
                'handle' => '@same',
                'email' => 'same@example.com',
                'fullName' => 'Same Creator',
                'platform' => 'tiktok',
                'lifecycleState' => 'needs_review',
            ],
        ]]);

        $this->assertSame([], $warnings);
    }

    public function test_meaningful_signals_get_action_routes(): void
    {
        $signals = $this->invokePrivate('meaningfulSignals', [
            [
                ['id' => 'evt-1', 'type' => 'creator_reply', 'handle' => '@creator', 'timestamp' => '2026-06-27 10:00:00'],
                ['id' => 'evt-2', 'type' => 'message_sent', 'handle' => '@creator', 'timestamp' => '2026-06-27 09:00:00'],
            ],
        ]);

        $this->assertCount(1, $signals);
        $this->assertSame('Draft response', $signals[0]['actionLabel']);
        $this->assertSame('/outreach?tab=conversations&handle=creator', $signals[0]['route']);
    }

    private function invokeNextBestAction(array $creator, array $relatedTasks = [], array $timeline = [], array $hardDisqualifiers = []): array
    {
        $service = $this->service();

        $method = new ReflectionMethod(OperatorViewService::class, 'nextBestAction');
        $method->setAccessible(true);

        return $method->invoke($service, $creator, $relatedTasks, $timeline, $hardDisqualifiers);
    }

    private function invokePrivate(string $methodName, array $arguments): mixed
    {
        $service = $this->service();

        $method = new ReflectionMethod(OperatorViewService::class, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($service, $arguments);
    }

    private function service(): OperatorViewService
    {
        return new OperatorViewService(
            $this->createMock(LegacyWorkbookStore::class),
            $this->createMock(InfluencerScoringService::class),
            $this->createMock(CreatorLifecycleService::class),
            $this->createMock(ProjectResolverService::class)
        );
    }
}
