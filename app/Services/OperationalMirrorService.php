<?php

namespace App\Services;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\MessageTemplate;
use App\Models\OutreachEvent;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OperationalMirrorService
{
    public function __construct(
        private LegacyWorkbookStore $sheets,
        private ProjectResolverService $projects,
    ) {}

    public function enabled(): bool
    {
        return config('outreach.operational_db.mode', 'dual') !== 'off';
    }

    public function shouldUseDatabaseReads(): bool
    {
        return config('outreach.operational_db.mode', 'dual') === 'database';
    }

    public function syncCreators(string $sheetId, ?array $rowNumbers = null): array
    {
        if (! $this->enabled() || ! config('outreach.sync.crm', true)) {
            return ['synced' => 0];
        }

        return DB::transaction(function () use ($sheetId, $rowNumbers) {
            $project = $this->projects->resolveByWorkbookId($sheetId);
            $rows = $this->filterRowsByNumbers($this->sheets->getRows($sheetId, 'Creators_CRM'), $rowNumbers);
            $synced = 0;

            foreach ($rows as $row) {
                $platform = strtolower(trim((string) ($row['Platform'] ?? '')));
                $handle = $this->normalizeHandle((string) ($row['Handle'] ?? ''));

                if ($platform === '' || $handle === '') {
                    continue;
                }

                $creator = $this->resolveCreator($project, $row);
                CreatorProfile::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'platform' => $platform,
                        'handle' => $handle,
                    ],
                    [
                        'creator_id' => $creator->id,
                        'username' => ltrim($handle, '@'),
                        'profile_url' => trim((string) ($row['DM_Link'] ?? '')) ?: null,
                        'dm_link' => trim((string) ($row['DM_Link'] ?? '')) ?: null,
                        'status' => trim((string) ($row['Status'] ?? 'DISCOVERED')),
                        'lifecycle_state' => strtolower(trim(str_replace(' ', '_', (string) ($row['Status'] ?? 'discovered')))),
                        'followers_count' => $this->nullableInt($row['Followers'] ?? null),
                        'engagement_rate_pct' => $this->nullableFloat($row['Engagement_Rate_%'] ?? null),
                        'preferred_channel' => trim((string) ($row['Preferred_Channel'] ?? '')) ?: null,
                        'last_content_at' => $this->parseDateTime($row['Last_Content_Date'] ?? null),
                        'value_score' => $this->nullableInt($row['Value_Score'] ?? null),
                        'value_bar' => trim((string) ($row['Value_Bar'] ?? '')) ?: null,
                        'duplicate_flag' => trim((string) ($row['Duplicate_Flag'] ?? '')) ?: null,
                        'accepted_flag' => $this->parseYesNo($row['Accepted_(Y/N)'] ?? null),
                        'follow_up_needed' => $this->parseYesNo($row['Follow_Up_Needed_(Y/N)'] ?? null),
                        'dm_sent_at' => $this->parseDateTime($row['DM_Sent_Date'] ?? null),
                        'responded_at' => $this->parseDateTime($row['Response_Date'] ?? null),
                        'source_provider' => 'legacy_workbook',
                        'source_reference' => 'Creators_CRM:'.(int) ($row['_row_number'] ?? 0),
                        'source_metadata' => [
                            'sheet_row_number' => (int) ($row['_row_number'] ?? 0),
                            'angle_assigned' => $this->nullableString($row['Angle_Assigned'] ?? null),
                            'commission_model' => $this->nullableString($row['Commission_Model'] ?? null),
                            'unique_tracking_code' => $this->nullableString($row['Unique_Tracking_Code'] ?? null),
                            'reaction_video_link' => $this->nullableString($row['Reaction_Video_Link'] ?? null),
                            'pdf_sales' => $this->nullableInt($row['PDF_Sales'] ?? null),
                            'product_sales' => $this->nullableInt($row['Product_Sales'] ?? null),
                            'total_revenue' => $this->nullableFloat($row['Total_Revenue'] ?? null),
                        ],
                        'last_synced_at' => now(),
                    ],
                );

                $synced++;
            }

            return ['synced' => $synced, 'project_id' => $project->id];
        });
    }

    public function syncMessageTemplates(string $sheetId, ?array $rowNumbers = null): array
    {
        if (! $this->enabled() || ! config('outreach.sync.messages', true)) {
            return ['synced' => 0];
        }

        return DB::transaction(function () use ($sheetId, $rowNumbers) {
            $project = $this->projects->resolveByWorkbookId($sheetId);
            $rows = $this->filterRowsByNumbers($this->sheets->getRows($sheetId, 'Message_Library'), $rowNumbers);
            $synced = 0;

            foreach ($rows as $row) {
                $rowNumber = (int) ($row['_row_number'] ?? 0);
                $angleId = trim((string) ($row['Angle_Name'] ?? ''));
                $copy = trim((string) ($row['DM_Template'] ?? ''));
                if ($rowNumber <= 0 || ($angleId === '' && $copy === '')) {
                    continue;
                }

                $meta = $this->parseMessageMeta((string) ($row['Psychological_Trigger'] ?? ''));

                MessageTemplate::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'angle_id' => $angleId !== '' ? $angleId : 'sheet-row:'.$rowNumber,
                        'platform' => strtolower(trim((string) ($row['Best_For_Platform'] ?? 'instagram'))),
                        'stage' => (string) ($meta['stage'] ?? 'cold_invite'),
                    ],
                    [
                        'niche' => (string) ($meta['niche'] ?? ''),
                        'copy' => $copy,
                        'notes' => (string) ($meta['notes'] ?? ''),
                        'psychological_trigger' => (string) ($meta['trigger'] ?? ''),
                        'metadata' => [
                            'source_reference' => 'Message_Library:'.$rowNumber,
                            'source_row_number' => $rowNumber,
                        ],
                    ],
                );

                $synced++;
            }

            return ['synced' => $synced, 'project_id' => $project->id];
        });
    }

    public function deleteMessageTemplateByRowNumber(string $sheetId, int $rowNumber): void
    {
        if (! $this->enabled() || ! config('outreach.sync.messages', true) || $rowNumber <= 0) {
            return;
        }

        $project = $this->projects->resolveByWorkbookId($sheetId);

        MessageTemplate::query()
            ->where('project_id', $project->id)
            ->where(function ($query) use ($rowNumber) {
                $query->where('metadata->source_row_number', $rowNumber)
                    ->orWhere('metadata->source_reference', 'Message_Library:'.$rowNumber);
            })
            ->delete();
    }

    public function syncTasks(string $sheetId, ?array $taskIds = null): array
    {
        if (! $this->enabled() || ! config('outreach.sync.tasks', true)) {
            return ['synced' => 0];
        }

        return DB::transaction(function () use ($sheetId, $taskIds) {
            $project = $this->projects->resolveByWorkbookId($sheetId);
            $rows = $this->sheets->getRows($sheetId, 'Task_Queue');
            if (is_array($taskIds) && $taskIds !== []) {
                $lookup = array_fill_keys(array_map('strval', $taskIds), true);
                $rows = array_values(array_filter($rows, fn (array $row) => isset($lookup[(string) ($row['Task_ID'] ?? '')])));
            }

            $synced = 0;
            foreach ($rows as $row) {
                $externalTaskKey = trim((string) ($row['Task_ID'] ?? ''));
                $platform = strtolower(trim((string) ($row['Platform'] ?? '')));
                $handle = $this->normalizeHandle((string) ($row['Handle'] ?? ''));
                if ($externalTaskKey === '' && $handle === '') {
                    continue;
                }

                $profile = $this->findCreatorProfile($project, $platform, $handle);
                $template = $this->findMessageTemplate($project, (string) ($row['Template_ID'] ?? ''));

                Task::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'external_task_key' => $externalTaskKey !== '' ? $externalTaskKey : 'sheet-row:'.(int) ($row['_row_number'] ?? 0),
                    ],
                    [
                        'creator_profile_id' => $profile?->id,
                        'message_template_id' => $template?->id,
                        'platform' => $platform ?: null,
                        'handle' => $handle ?: null,
                        'task_type' => trim((string) ($row['Task_Type'] ?? 'UNKNOWN')),
                        'priority' => strtoupper(trim((string) ($row['Priority'] ?? 'LOW'))),
                        'status' => strtoupper(trim((string) ($row['Status'] ?? 'PENDING'))),
                        'due_at' => $this->parseDateTime($row['Due_At'] ?? null),
                        'open_url' => trim((string) ($row['Open_URL'] ?? '')) ?: null,
                        'message_draft' => trim((string) ($row['Message_Draft'] ?? '')) ?: null,
                        'source_provider' => 'legacy_workbook',
                        'source_reference' => 'Task_Queue:'.(int) ($row['_row_number'] ?? 0),
                        'notes' => trim((string) ($row['Notes'] ?? '')) ?: null,
                        'completed_at' => $this->parseDateTime($row['Completed_At'] ?? null),
                        'metadata' => [
                            'sheet_row_number' => (int) ($row['_row_number'] ?? 0),
                            'created_at_source' => $this->nullableString($row['Created_At'] ?? null),
                        ],
                    ],
                );

                $synced++;
            }

            return ['synced' => $synced, 'project_id' => $project->id];
        });
    }

    public function syncOutreachEvents(string $sheetId, ?array $eventIds = null): array
    {
        if (! $this->enabled() || ! config('outreach.sync.outreach', true)) {
            return ['synced' => 0];
        }

        return DB::transaction(function () use ($sheetId, $eventIds) {
            $project = $this->projects->resolveByWorkbookId($sheetId);
            $rows = $this->sheets->getRows($sheetId, 'Outreach_Log');
            if (is_array($eventIds) && $eventIds !== []) {
                $lookup = array_fill_keys(array_map('strval', $eventIds), true);
                $rows = array_values(array_filter($rows, fn (array $row) => isset($lookup[(string) ($row['Event_ID'] ?? '')])));
            }

            $synced = 0;
            foreach ($rows as $row) {
                $eventKey = trim((string) ($row['Event_ID'] ?? '')) ?: 'sheet-row:'.(int) ($row['_row_number'] ?? 0);
                $platform = strtolower(trim((string) ($row['Platform'] ?? '')));
                $handle = $this->normalizeHandle((string) ($row['Handle'] ?? ''));
                $profile = $this->findCreatorProfile($project, $platform, $handle);
                $template = $this->findMessageTemplate($project, (string) ($row['Template_ID'] ?? ''));
                $task = Task::query()
                    ->where('project_id', $project->id)
                    ->where('platform', $platform)
                    ->where('handle', $handle)
                    ->latest('created_at')
                    ->first();

                OutreachEvent::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'external_event_key' => $eventKey,
                    ],
                    [
                        'creator_profile_id' => $profile?->id,
                        'task_id' => $task?->id,
                        'message_template_id' => $template?->id,
                        'platform' => $platform ?: null,
                        'handle' => $handle ?: null,
                        'channel' => trim((string) ($row['Channel'] ?? '')) ?: null,
                        'event_type' => trim((string) ($row['Event_Type'] ?? 'UNKNOWN')),
                        'sender_account' => trim((string) ($row['Sender_Account'] ?? '')) ?: null,
                        'sent_at' => $this->parseDateTime($row['Sent_At'] ?? null),
                        'status' => trim((string) ($row['Status'] ?? '')) ?: null,
                        'url' => trim((string) ($row['URL'] ?? '')) ?: null,
                        'notes' => trim((string) ($row['Notes'] ?? '')) ?: null,
                        'metadata' => [
                            'source_reference' => 'Outreach_Log:'.(int) ($row['_row_number'] ?? 0),
                            'sheet_row_number' => (int) ($row['_row_number'] ?? 0),
                        ],
                    ],
                );

                $synced++;
            }

            return ['synced' => $synced, 'project_id' => $project->id];
        });
    }

    public function resolveProject(string $sheetId): Project
    {
        return $this->projects->resolveByWorkbookId($sheetId);
    }

    private function filterRowsByNumbers(array $rows, ?array $rowNumbers): array
    {
        if (! is_array($rowNumbers) || $rowNumbers === []) {
            return $rows;
        }

        $lookup = array_fill_keys(array_map('intval', $rowNumbers), true);

        return array_values(array_filter($rows, fn (array $row) => isset($lookup[(int) ($row['_row_number'] ?? 0)])));
    }

    private function resolveCreator(Project $project, array $row): Creator
    {
        $identityKey = $this->creatorIdentityKey($row);
        $displayName = trim((string) ($row['Name'] ?? ''));
        $email = trim((string) ($row['Contact_Email'] ?? ''));

        $creator = Creator::where('project_id', $project->id)
            ->where('external_identity_key', $identityKey)
            ->first();

        if (! $creator && $email !== '') {
            $creator = Creator::where('project_id', $project->id)
                ->where('primary_email', $email)
                ->first();
        }

        if (! $creator) {
            $creator = new Creator;
            $creator->project_id = $project->id;
            $creator->external_identity_key = $identityKey;
        }

        $creator->display_name = $displayName !== '' ? $displayName : ($creator->display_name ?: ltrim((string) ($row['Handle'] ?? ''), '@'));
        $creator->primary_email = $email !== '' ? $email : $creator->primary_email;
        $creator->country = trim((string) ($row['Country'] ?? '')) ?: $creator->country;
        $creator->city = trim((string) ($row['City'] ?? '')) ?: $creator->city;
        $creator->primary_language = trim((string) ($row['Primary_Language'] ?? '')) ?: $creator->primary_language;
        $creator->niche_category = trim((string) ($row['Niche_Category'] ?? '')) ?: $creator->niche_category;
        $creator->notes = trim((string) ($row['Notes'] ?? '')) ?: $creator->notes;
        $creator->metadata = array_filter([
            'linked_profiles' => $this->extractTaggedValue((string) ($row['Notes'] ?? ''), 'linked_profiles'),
            'identity_primary' => $this->extractTaggedValue((string) ($row['Notes'] ?? ''), 'identity_primary'),
            'imported_from' => 'Creators_CRM:'.(int) ($row['_row_number'] ?? 0),
        ], fn ($value) => $value !== null && $value !== '');
        $creator->save();

        return $creator;
    }

    private function findCreatorProfile(Project $project, string $platform, string $handle): ?CreatorProfile
    {
        if ($platform === '' || $handle === '') {
            return null;
        }

        return CreatorProfile::query()
            ->where('project_id', $project->id)
            ->where('platform', $platform)
            ->where('handle', $handle)
            ->first();
    }

    private function findMessageTemplate(Project $project, string $angleId): ?MessageTemplate
    {
        $angleId = trim($angleId);
        if ($angleId === '') {
            return null;
        }

        return MessageTemplate::query()
            ->where('project_id', $project->id)
            ->where('angle_id', $angleId)
            ->first();
    }

    private function creatorIdentityKey(array $row): string
    {
        $notes = (string) ($row['Notes'] ?? '');
        $explicitIdentity = $this->extractTaggedValue($notes, 'creator_identity_id');
        if ($explicitIdentity) {
            return 'sheet_identity:'.strtolower($explicitIdentity);
        }

        $email = strtolower(trim((string) ($row['Contact_Email'] ?? '')));
        if ($email !== '') {
            return 'email:'.$email;
        }

        $name = strtolower(trim((string) ($row['Name'] ?? '')));
        if ($name !== '') {
            return 'name:'.$name;
        }

        return 'profile:'.strtolower(trim((string) ($row['Platform'] ?? ''))).'|'.strtolower($this->normalizeHandle((string) ($row['Handle'] ?? '')));
    }

    private function extractTaggedValue(string $text, string $key): ?string
    {
        if (preg_match('/(?:^|[;|\s])'.preg_quote($key, '/').'=([^;|]+)/i', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function parseMessageMeta(string $psychologicalTrigger): array
    {
        $text = trim($psychologicalTrigger);
        $result = [
            'trigger' => $text,
            'stage' => 'cold_invite',
            'niche' => '',
            'notes' => '',
        ];

        if (! str_contains($text, '|| META:')) {
            return $result;
        }

        [$trigger, $metaJson] = explode('|| META:', $text, 2);
        $decoded = json_decode(trim($metaJson), true);
        if (! is_array($decoded)) {
            $result['trigger'] = trim($trigger);

            return $result;
        }

        return [
            'trigger' => trim($trigger),
            'stage' => (string) ($decoded['stage'] ?? 'cold_invite'),
            'niche' => (string) ($decoded['niche'] ?? ''),
            'notes' => (string) ($decoded['notes'] ?? ''),
        ];
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || str_starts_with($value, '=')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseYesNo(mixed $value): bool
    {
        $normalized = strtolower(trim((string) ($value ?? '')));

        return in_array($normalized, ['y', 'yes', 'true', '1'], true);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/[^0-9.-]/', '', trim((string) $value));
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/[^0-9.-]/', '', trim((string) $value));
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function normalizeHandle(string $handle): string
    {
        $handle = trim($handle);
        if ($handle === '') {
            return '';
        }

        return str_starts_with($handle, '@') ? $handle : '@'.$handle;
    }
}
