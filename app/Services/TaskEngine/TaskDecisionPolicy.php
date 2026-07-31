<?php

namespace App\Services\TaskEngine;

use App\Models\CreatorProfile;
use Carbon\Carbon;

class TaskDecisionPolicy
{
    public function buildMetadata(
        CreatorProfile $profile,
        string $taskType,
        string $priority,
        Carbon $dueAt,
        string $actionableChannel,
        array $metadata = []
    ): array {
        $now = now();
        $valueScore = max(0, min(100, (int) ($profile->value_score ?? 0)));
        $followers = (int) ($profile->followers_count ?? 0);
        $engagement = (float) ($profile->engagement_rate_pct ?? 0);
        $priority = strtoupper($priority);
        $sourceRule = (string) ($metadata['source_rule'] ?? 'task_engine');
        $daysSinceOutreach = $profile->last_outreach_at instanceof Carbon
            ? max(0, $profile->last_outreach_at->diffInDays($now))
            : null;

        $score = $valueScore
            + $this->priorityWeight($priority)
            + ($dueAt->lessThanOrEqualTo($now) ? 18 : 0)
            + ($followers >= 50000 ? 8 : ($followers >= 10000 ? 4 : 0))
            + ($engagement >= 4.0 ? 6 : ($engagement >= 2.0 ? 3 : 0));

        if ($profile->responded_at instanceof Carbon) {
            $score += 24;
        }
        if ($profile->accepted_flag) {
            $score += 18;
        }
        if (! empty($metadata['follow_up_variant'])) {
            $score += 14;
        }
        if ($this->isSupportTaskType($taskType)) {
            $score -= empty($metadata['follow_up_variant']) ? 26 : 14;
        }

        $decision = $this->copyForTask($taskType, $sourceRule, $daysSinceOutreach);
        $riskFlags = [];

        if ($actionableChannel === 'email' && ! $this->profileHasUsableEmail($profile)) {
            $riskFlags[] = 'missing_email';
            $score -= 18;
        }
        if ($profile->duplicate_flag) {
            $riskFlags[] = 'duplicate_risk';
            $score -= 12;
        }
        if ($profile->last_outreach_at instanceof Carbon && $profile->last_outreach_at->greaterThan($now->copy()->subDay())) {
            $riskFlags[] = 'recent_outreach';
            $score -= 10;
        }

        $qualitySignals = [];
        if ($dueAt->lessThanOrEqualTo($now)) {
            $qualitySignals[] = 'overdue';
        }
        if ($valueScore >= 75) {
            $qualitySignals[] = 'high_value';
        }
        if ($profile->responded_at instanceof Carbon) {
            $qualitySignals[] = 'reply_signal';
        }
        if ($profile->accepted_flag) {
            $qualitySignals[] = 'accepted_handoff';
        }
        if (! empty($metadata['follow_up_variant'])) {
            $qualitySignals[] = 'follow_up_due';
        }
        if ($this->isSupportTaskType($taskType)) {
            $qualitySignals[] = empty($metadata['follow_up_variant']) ? 'warmup_support' : 'soft_follow_up';
        }
        if ($riskFlags !== []) {
            $qualitySignals[] = 'verify_before_action';
        }

        $scoreInputs = [
            'value_score' => $valueScore,
            'priority_weight' => $this->priorityWeight($priority),
            'due_now_bonus' => $dueAt->lessThanOrEqualTo($now) ? 18 : 0,
            'followers_bonus' => $followers >= 50000 ? 8 : ($followers >= 10000 ? 4 : 0),
            'engagement_bonus' => $engagement >= 4.0 ? 6 : ($engagement >= 2.0 ? 3 : 0),
            'reply_bonus' => $profile->responded_at instanceof Carbon ? 24 : 0,
            'accepted_bonus' => $profile->accepted_flag ? 18 : 0,
            'follow_up_bonus' => ! empty($metadata['follow_up_variant']) ? 14 : 0,
            'support_task_penalty' => $this->isSupportTaskType($taskType) ? (empty($metadata['follow_up_variant']) ? -26 : -14) : 0,
            'risk_penalty' => -1 * (in_array('missing_email', $riskFlags, true) ? 18 : 0)
                - (in_array('duplicate_risk', $riskFlags, true) ? 12 : 0)
                - (in_array('recent_outreach', $riskFlags, true) ? 10 : 0),
        ];

        return [
            'decision_score' => max(0, min(150, (int) round($score))),
            'decision_reason' => $decision['reason'],
            'why_now' => $decision['why'],
            'success_condition' => $decision['success'],
            'fallback_action' => $decision['fallback'],
            'decision_confidence' => (int) $decision['confidence'],
            'decision_source' => $sourceRule,
            'decision_risk_flags' => $riskFlags,
            'task_quality_signals' => array_values(array_unique($qualitySignals)),
            'decision_score_inputs' => $scoreInputs,
        ];
    }

    private function copyForTask(string $taskType, string $sourceRule, ?int $daysSinceOutreach): array
    {
        return match ($taskType) {
            'REVIEW_CREATOR' => [
                'reason' => 'The creator has replied, so this needs a human decision before momentum cools.',
                'why' => 'A reply is the strongest buying signal in the queue.',
                'success' => 'Log the reply outcome and move the creator to the correct next stage.',
                'fallback' => 'If the reply is unclear, keep the creator in review and add a short note.',
                'confidence' => 92,
            ],
            'NEGOTIATE_TERMS' => [
                'reason' => 'This creator is already in negotiation, so the next useful move is to clarify terms.',
                'why' => 'Active deal conversations lose value when ownership or next steps are unclear.',
                'success' => 'Terms, timing, budget, or the next approval step is logged.',
                'fallback' => 'If there is no real negotiation signal, mark the creator lost or move them back to review.',
                'confidence' => 88,
            ],
            'CONFIRM_POSTED' => [
                'reason' => 'This creator appears accepted, so delivery needs follow-through.',
                'why' => 'Accepted creators only create value once the promised content is verified.',
                'success' => 'The post status is confirmed and the creator is moved forward or followed up.',
                'fallback' => 'If nothing is live yet, set the next check-in date.',
                'confidence' => 86,
            ],
            'CHECK_IN' => [
                'reason' => $sourceRule === 'follow_up_exhausted'
                    ? 'Several attempts have not produced a reply, so this needs a keep-or-clean-up decision.'
                    : 'There is an active or stale conversation state that needs confirmation.',
                'why' => $sourceRule === 'follow_up_exhausted'
                    ? 'Repeated no-reply creators should not keep consuming daily attention.'
                    : 'The system needs one clean status update to avoid stale pipeline data.',
                'success' => 'The current relationship status is logged.',
                'fallback' => 'If there is still no signal, archive or snooze the creator with a reason.',
                'confidence' => $sourceRule === 'follow_up_exhausted' ? 84 : 78,
            ],
            'DM_FOLLOWUP' => [
                'reason' => $daysSinceOutreach !== null
                    ? "First outreach was sent {$daysSinceOutreach} days ago and no reply is logged."
                    : 'A follow-up is due and no reply is logged.',
                'why' => 'Follow-ups work best when they are timely, specific, and capped.',
                'success' => 'A follow-up is sent or a softer touchpoint is logged.',
                'fallback' => 'If this is the final attempt, move the creator to check-in instead of sending another message.',
                'confidence' => 82,
            ],
            'EMAIL_SEND' => [
                'reason' => 'A usable email exists, so this creator can be reached through a cleaner outreach channel.',
                'why' => 'Email avoids some platform friction and is easier to track as a business touchpoint.',
                'success' => 'The email outreach is sent and logged.',
                'fallback' => 'If the email is invalid, switch to DM or mark contact data as incomplete.',
                'confidence' => 80,
            ],
            'DM_INVITE' => [
                'reason' => 'This creator is ready for first outreach and no sent message is logged yet.',
                'why' => 'The fastest path to validation is a clear first message while the creator is still fresh.',
                'success' => 'The first outreach is sent and a follow-up date is created.',
                'fallback' => 'If the profile is not a fit, archive it with the reason.',
                'confidence' => 76,
            ],
            'COMMENT_ON_POST' => [
                'reason' => 'A warm public interaction is safer than pushing another direct message right now.',
                'why' => 'Lightweight warm touches can revive attention without feeling repetitive.',
                'success' => 'A relevant interaction is completed and logged.',
                'fallback' => 'If there is no suitable recent post, choose a follow or check-in instead.',
                'confidence' => 74,
            ],
            'FOLLOW_REQUEST' => [
                'reason' => 'This is a support warm-up action for a high-value creator, not the main outreach step.',
                'why' => 'A small visible signal can make the first message feel less cold without consuming the whole queue.',
                'success' => 'The follow status is logged and the next real touchpoint stays clear.',
                'fallback' => 'If following is not possible, open the profile and choose another warm-up action.',
                'confidence' => 72,
            ],
            'ARCHIVE_CREATOR' => [
                'reason' => 'This creator is unlikely to produce value unless new information appears.',
                'why' => 'Removing low-signal creators keeps the active queue focused.',
                'success' => 'The creator is archived with a clear reason.',
                'fallback' => 'If there is a recent positive signal, snooze instead of archiving.',
                'confidence' => 76,
            ],
            default => [
                'reason' => 'This is the next workflow step based on the creator state.',
                'why' => 'Keeping one clear next action prevents stale CRM rows.',
                'success' => 'The action is completed and the relationship state is updated.',
                'fallback' => 'If the action does not fit, skip it and choose the correct replacement.',
                'confidence' => 68,
            ],
        };
    }

    private function profileHasUsableEmail(?CreatorProfile $profile): bool
    {
        $email = trim((string) optional($profile?->creator)->primary_email);

        if ($email === '') {
            return false;
        }

        $lowered = strtolower($email);
        if (in_array($lowered, ['none', 'null', 'undefined', 'n/a', 'na', '-', 'no email', 'unknown'], true)) {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isSupportTaskType(string $taskType): bool
    {
        return in_array(strtoupper($taskType), ['FOLLOW_REQUEST', 'COMMENT_ON_POST'], true);
    }

    private function priorityWeight(string $priority): int
    {
        return match ($priority) {
            'URGENT' => 40,
            'HIGH' => 25,
            'MEDIUM' => 15,
            default => 5,
        };
    }
}
