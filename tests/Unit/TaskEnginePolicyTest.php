<?php

namespace Tests\Unit;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Services\TaskEngine\TaskAutomationSettings;
use App\Services\TaskEngine\TaskDecisionPolicy;
use Carbon\Carbon;
use Tests\TestCase;

class TaskEnginePolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_decision_policy_exposes_auditable_score_inputs_and_risk_flags(): void
    {
        Carbon::setTestNow('2026-06-27 12:00:00');

        $profile = new CreatorProfile([
            'value_score' => 72,
            'followers_count' => 18000,
            'engagement_rate_pct' => 3.2,
            'duplicate_flag' => true,
            'last_outreach_at' => Carbon::parse('2026-06-27 09:00:00'),
        ]);
        $profile->setRelation('creator', new Creator(['primary_email' => 'unknown']));

        $metadata = (new TaskDecisionPolicy)->buildMetadata(
            $profile,
            'EMAIL_SEND',
            'HIGH',
            Carbon::parse('2026-06-27 10:00:00'),
            'email',
            ['source_rule' => 'initial_task_selection']
        );

        $this->assertSame('A usable email exists, so this creator can be reached through a cleaner outreach channel.', $metadata['decision_reason']);
        $this->assertContains('missing_email', $metadata['decision_risk_flags']);
        $this->assertContains('duplicate_risk', $metadata['decision_risk_flags']);
        $this->assertContains('recent_outreach', $metadata['decision_risk_flags']);
        $this->assertSame(72, $metadata['decision_score_inputs']['value_score']);
        $this->assertSame(25, $metadata['decision_score_inputs']['priority_weight']);
        $this->assertLessThan(150, $metadata['decision_score']);
    }

    public function test_task_automation_settings_sanitize_values_and_permissions(): void
    {
        $settings = new TaskAutomationSettings;

        $sanitized = $settings->sanitize([
            'max_active_tasks' => -10,
            'time_pressure_mode' => 1,
            'settings_edit_scope' => 'everyone',
        ]);

        $this->assertSame(0, $sanitized['max_active_tasks']);
        $this->assertTrue($sanitized['time_pressure_mode']);
        $this->assertSame('admins', $sanitized['settings_edit_scope']);
        $this->assertTrue($settings->canEdit(['settings_edit_scope' => 'all_seats'], 'member'));
        $this->assertFalse($settings->canEdit(['settings_edit_scope' => 'admins'], 'member'));
    }
}
