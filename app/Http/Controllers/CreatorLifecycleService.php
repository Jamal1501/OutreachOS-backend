<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;

class CreatorLifecycleService
{
    public const STATES = [
        'discovered',
        'needs_review',
        'enriched',
        'duplicate_review_needed',
        'approved_for_outreach',
        'queued',
        'contacted',
        'replied',
        'negotiating',
        'accepted',
        'declined',
        'won',
        'lost',
        'archived',
    ];

    private const TRANSITIONS = [
        'discovered' => ['needs_review', 'enriched', 'duplicate_review_needed', 'approved_for_outreach', 'archived'],
        'needs_review' => ['enriched', 'duplicate_review_needed', 'approved_for_outreach', 'queued', 'contacted', 'archived'],
        'enriched' => ['duplicate_review_needed', 'approved_for_outreach', 'queued', 'contacted', 'archived'],
        'duplicate_review_needed' => ['needs_review', 'approved_for_outreach', 'archived'],
        'approved_for_outreach' => ['queued', 'contacted', 'archived'],
        'queued' => ['contacted', 'archived'],
        'contacted' => ['replied', 'negotiating', 'accepted', 'declined', 'lost', 'archived'],
        'replied' => ['negotiating', 'accepted', 'declined', 'won', 'lost', 'archived'],
        'negotiating' => ['won', 'lost', 'accepted', 'declined', 'archived'],
        'accepted' => ['won', 'archived'],
        'declined' => ['lost', 'archived'],
        'won' => ['archived'],
        'lost' => ['archived'],
        'archived' => [],
    ];

    public function normalizeState(string $rawStatus, string $enrichmentStatus = 'pending'): string
    {
        return match (Str::upper(trim($rawStatus))) {
            'DISCOVERED' => 'discovered',
            'NEEDS_REVIEW' => 'needs_review',
            'ENRICHED', 'NEW' => 'enriched',
            'DUPLICATE_REVIEW_NEEDED' => 'duplicate_review_needed',
            'APPROVED_FOR_OUTREACH' => 'approved_for_outreach',
            'QUEUED' => 'queued',
            'CONTACTED', 'FOLLOW_REQUEST_SENT', 'FOLLOWED_UP' => 'contacted',
            'REPLIED' => 'replied',
            'NEGOTIATING' => 'negotiating',
            'ACCEPTED' => 'accepted',
            'DECLINED' => 'declined',
            'WON' => 'won',
            'LOST' => 'lost',
            'ARCHIVED' => 'archived',
            default => $enrichmentStatus === 'enriched' ? 'enriched' : 'discovered',
        };
    }

    public function isValidState(string $state): bool
    {
        return in_array($state, self::STATES, true);
    }

    public function canTransition(string $fromState, string $toState): bool
    {
        if ($fromState === $toState) {
            return true;
        }

        return in_array($toState, self::TRANSITIONS[$fromState] ?? [], true);
    }

    public function sheetStatusForState(string $state): string
    {
        return match ($state) {
            'needs_review' => 'NEEDS_REVIEW',
            'duplicate_review_needed' => 'DUPLICATE_REVIEW_NEEDED',
            'approved_for_outreach' => 'APPROVED_FOR_OUTREACH',
            'queued' => 'QUEUED',
            'contacted' => 'CONTACTED',
            'replied' => 'REPLIED',
            'negotiating' => 'NEGOTIATING',
            'accepted' => 'ACCEPTED',
            'declined' => 'DECLINED',
            'won' => 'WON',
            'lost' => 'LOST',
            'archived' => 'ARCHIVED',
            'enriched' => 'ENRICHED',
            default => 'DISCOVERED',
        };
    }

    public function eventTypeForTransition(string $fromState, string $toState): string
    {
        return match ($toState) {
            'contacted' => 'OUTREACH_SENT',
            'replied' => 'REPLY_RECEIVED',
            'won', 'accepted' => 'DEAL_WON',
            'lost', 'declined' => 'DEAL_LOST',
            default => 'STATE_CHANGED',
        };
    }

    public function applyTransition(array $creatorRow, string $fromState, string $toState, array $context = []): array
    {
        $creatorRow['Status'] = $this->sheetStatusForState($toState);

        if (in_array($toState, ['contacted', 'replied', 'negotiating', 'accepted', 'declined', 'won', 'lost'], true)) {
            $creatorRow['Response_Date'] = in_array($toState, ['replied', 'negotiating', 'accepted', 'declined', 'won', 'lost'], true)
                ? now()->toDateString()
                : (string) ($creatorRow['Response_Date'] ?? '');
        }

        if ($toState === 'contacted') {
            $creatorRow['DM_Sent_Date'] = now()->toDateString();
            $creatorRow['Follow_Up_Needed_(Y/N)'] = 'Y';
        }

        if (in_array($toState, ['accepted', 'won'], true)) {
            $creatorRow['Accepted_(Y/N)'] = 'Y';
            $creatorRow['Follow_Up_Needed_(Y/N)'] = 'N';
        }

        if (in_array($toState, ['declined', 'lost', 'archived'], true)) {
            $creatorRow['Follow_Up_Needed_(Y/N)'] = 'N';
        }

        $reason = trim((string) ($context['reason'] ?? ''));
        $actor = trim((string) ($context['actor'] ?? 'operator'));
        $notes = trim((string) ($context['notes'] ?? ''));
        $transitionNote = sprintf(
            'manual_transition=%s>%s; actor=%s; transitioned_at=%s%s%s',
            $fromState,
            $toState,
            $actor,
            now()->toDateTimeString(),
            $reason !== '' ? '; reason=' . $reason : '',
            $notes !== '' ? '; note=' . str_replace(';', ',', $notes) : ''
        );

        $creatorRow['Notes'] = $this->appendNote((string) ($creatorRow['Notes'] ?? ''), $transitionNote);

        return $creatorRow;
    }

    public function freshnessLabel(?string $date): string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return 'unknown';
        }

        try {
            $days = Carbon::parse($date)->diffInDays(now());
        } catch (\Throwable) {
            return 'unknown';
        }

        return match (true) {
            $days <= 1 => 'today',
            $days <= 7 => '7d',
            $days <= 30 => 'stale',
            default => 'old',
        };
    }

    public function inferEvidenceSource(array $creatorRow, string $field): string
    {
        $notes = Str::lower((string) ($creatorRow['Notes'] ?? ''));
        $status = Str::upper(trim((string) ($creatorRow['Status'] ?? '')));

        if (Str::contains($notes, 'manual_transition=')) {
            return 'human edited';
        }

        if (Str::contains($notes, ['source=ig_enriched', 'source=tiktok_enriched'])) {
            return in_array($field, ['followers', 'engagementRate', 'email', 'fullName', 'niche'], true)
                ? 'enriched'
                : 'scraped';
        }

        if (in_array($status, ['DISCOVERED', 'NEEDS_REVIEW'], true)) {
            return 'scraped';
        }

        return 'unknown';
    }

    private function appendNote(string $existing, string $incoming): string
    {
        $existing = trim($existing);
        $incoming = trim($incoming);

        if ($incoming === '') {
            return $existing;
        }

        if ($existing === '') {
            return $incoming;
        }

        return $existing . ' | ' . $incoming;
    }
}
