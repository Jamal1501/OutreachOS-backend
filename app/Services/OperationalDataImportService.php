<?php

namespace App\Services;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\DiscoveryItem;
use App\Models\DiscoveryRun;
use App\Models\EnrichmentJob;
use App\Models\MessageTemplate;
use App\Models\OutreachEvent;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OperationalDataImportService
{
    public function __construct(private GoogleSheetsService $sheets)
    {
    }

    public function importWorkbook(string $sheetId, ?string $projectName = null, bool $truncate = false): array
    {
        return DB::transaction(function () use ($sheetId, $projectName, $truncate) {
            $project = Project::firstOrCreate(
                ['workbook_id' => $sheetId],
                [
                    'name' => $projectName ?: 'Imported workbook ' . substr($sheetId, 0, 8),
                    'status' => 'active',
                    'metadata' => ['import_source' => 'google_sheets'],
                ],
            );

            if ($truncate) {
                $this->truncateProjectData($project);
            }

            $creatorProfileMap = [];
            $summary = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'creators' => $this->importCreators($project, $sheetId, $creatorProfileMap),
                'message_templates' => $this->importMessageTemplates($project, $sheetId),
                'tasks' => $this->importTasks($project, $sheetId, $creatorProfileMap),
                'outreach_events' => $this->importOutreachEvents($project, $sheetId, $creatorProfileMap),
                'discovery' => $this->importDiscoverySheets($project, $sheetId),
                'enrichment_snapshots' => $this->importEnrichmentSnapshots($project, $sheetId),
            ];

            return $summary;
        });
    }

    private function truncateProjectData(Project $project): void
    {
        OutreachEvent::where('project_id', $project->id)->delete();
        Task::where('project_id', $project->id)->delete();
        MessageTemplate::where('project_id', $project->id)->delete();
        DiscoveryItem::where('project_id', $project->id)->delete();
        EnrichmentJob::where('project_id', $project->id)->delete();
        DiscoveryRun::where('project_id', $project->id)->delete();
        CreatorProfile::where('project_id', $project->id)->delete();
        Creator::where('project_id', $project->id)->delete();
    }

    private function importCreators(Project $project, string $sheetId, array &$creatorProfileMap): array
    {
        $rows = $this->sheets->getRows($sheetId, 'Creators_CRM');
        $createdCreators = 0;
        $createdProfiles = 0;

        foreach ($rows as $row) {
            $platform = strtolower(trim((string) ($row['Platform'] ?? '')));
            $handle = $this->normalizeHandle((string) ($row['Handle'] ?? ''));

            if ($platform === '' || $handle === '') {
                continue;
            }

            $creator = $this->resolveCreator($project, $row, $createdCreators);

            $status = trim((string) ($row['Status'] ?? 'DISCOVERED'));
            $profile = CreatorProfile::updateOrCreate(
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
                    'status' => $status,
                    'lifecycle_state' => strtolower(trim(str_replace(' ', '_', $status))),
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
                    'source_provider' => 'google_sheets',
                    'source_reference' => 'Creators_CRM:' . (int) ($row['_row_number'] ?? 0),
                    'source_metadata' => [
                        'angle_assigned' => $this->nullableString($row['Angle_Assigned'] ?? null),
                        'commission_model' => $this->nullableString($row['Commission_Model'] ?? null),
                        'unique_tracking_code' => $this->nullableString($row['Unique_Tracking_Code'] ?? null),
                        'reaction_video_link' => $this->nullableString($row['Reaction_Video_Link'] ?? null),
                        'pdf_sales' => $this->nullableInt($row['PDF_Sales'] ?? null),
                        'product_sales' => $this->nullableInt($row['Product_Sales'] ?? null),
                        'total_revenue' => $this->nullableFloat($row['Total_Revenue'] ?? null),
                        'sheet_row_number' => (int) ($row['_row_number'] ?? 0),
                    ],
                    'last_synced_at' => now(),
                ],
            );

            if ($profile->wasRecentlyCreated) {
                $createdProfiles++;
            }

            $creatorProfileMap[$platform . '|' . strtolower($handle)] = $profile->id;
        }

        return [
            'rows_read' => count($rows),
            'creators_total' => Creator::where('project_id', $project->id)->count(),
            'profiles_total' => CreatorProfile::where('project_id', $project->id)->count(),
            'creators_created' => $createdCreators,
            'profiles_created' => $createdProfiles,
        ];
    }

    private function resolveCreator(Project $project, array $row, int &$createdCreators): Creator
    {
        $identityKey = $this->creatorIdentityKey($row);
        $displayName = trim((string) ($row['Name'] ?? ''));
        $email = trim((string) ($row['Contact_Email'] ?? ''));

        $creator = Creator::where('project_id', $project->id)
            ->where('external_identity_key', $identityKey)
            ->first();

        if (!$creator && $email !== '') {
            $creator = Creator::where('project_id', $project->id)
                ->where('primary_email', $email)
                ->first();
        }

        if (!$creator) {
            $creator = new Creator();
            $creator->project_id = $project->id;
            $creator->external_identity_key = $identityKey;
            $createdCreators++;
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
            'imported_from' => 'Creators_CRM:' . (int) ($row['_row_number'] ?? 0),
        ], fn ($value) => $value !== null && $value !== '');
        $creator->save();

        return $creator;
    }

    private function importMessageTemplates(Project $project, string $sheetId): array
    {
        $rows = $this->sheets->getRows($sheetId, 'Message_Library');
        $created = 0;

        foreach ($rows as $row) {
            $angleId = trim((string) ($row['Angle_Name'] ?? ''));
            $copy = trim((string) ($row['DM_Template'] ?? ''));

            if ($angleId === '' && $copy === '') {
                continue;
            }

            $meta = $this->parseMessageMeta((string) ($row['Psychological_Trigger'] ?? ''));
            $template = MessageTemplate::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'angle_id' => $angleId,
                    'platform' => strtolower(trim((string) ($row['Best_For_Platform'] ?? 'instagram'))),
                    'stage' => (string) ($meta['stage'] ?? 'cold_invite'),
                ],
                [
                    'niche' => (string) ($meta['niche'] ?? ''),
                    'copy' => $copy,
                    'notes' => (string) ($meta['notes'] ?? ''),
                    'psychological_trigger' => (string) ($meta['trigger'] ?? ''),
                    'metadata' => ['source_row_number' => (int) ($row['_row_number'] ?? 0)],
                ],
            );

            if ($template->wasRecentlyCreated) {
                $created++;
            }
        }

        return [
            'rows_read' => count($rows),
            'total' => MessageTemplate::where('project_id', $project->id)->count(),
            'created' => $created,
        ];
    }

    private function importTasks(Project $project, string $sheetId, array $creatorProfileMap): array
    {
        $rows = $this->sheets->getRows($sheetId, 'Task_Queue');
        $created = 0;

        foreach ($rows as $row) {
            $externalTaskKey = trim((string) ($row['Task_ID'] ?? ''));
            $platform = strtolower(trim((string) ($row['Platform'] ?? '')));
            $handle = $this->normalizeHandle((string) ($row['Handle'] ?? ''));
            $profileId = $creatorProfileMap[$platform . '|' . strtolower($handle)] ?? null;
            $template = null;

            if (trim((string) ($row['Template_ID'] ?? '')) !== '') {
                $template = MessageTemplate::where('project_id', $project->id)
                    ->where('angle_id', (string) $row['Template_ID'])
                    ->first();
            }

            $task = Task::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'external_task_key' => $externalTaskKey !== '' ? $externalTaskKey : 'sheet-row:' . (int) ($row['_row_number'] ?? 0),
                ],
                [
                    'creator_profile_id' => $profileId,
                    'message_template_id' => $template?->id,
                    'platform' => $platform ?: null,
                    'handle' => $handle ?: null,
                    'task_type' => trim((string) ($row['Task_Type'] ?? 'UNKNOWN')),
                    'priority' => strtoupper(trim((string) ($row['Priority'] ?? 'LOW'))),
                    'status' => strtoupper(trim((string) ($row['Status'] ?? 'PENDING'))),
                    'due_at' => $this->parseDateTime($row['Due_At'] ?? null),
                    'open_url' => trim((string) ($row['Open_URL'] ?? '')) ?: null,
                    'message_draft' => trim((string) ($row['Message_Draft'] ?? '')) ?: null,
                    'source_provider' => 'google_sheets',
                    'source_reference' => 'Task_Queue:' . (int) ($row['_row_number'] ?? 0),
                    'notes' => trim((string) ($row['Notes'] ?? '')) ?: null,
                    'completed_at' => $this->parseDateTime($row['Completed_At'] ?? null),
                    'metadata' => ['created_at_source' => $this->nullableString($row['Created_At'] ?? null)],
                ],
            );

            if ($task->wasRecentlyCreated) {
                $created++;
            }
        }

        return [
            'rows_read' => count($rows),
            'total' => Task::where('project_id', $project->id)->count(),
            'created' => $created,
        ];
    }

    private function importOutreachEvents(Project $project, string $sheetId, array $creatorProfileMap): array
    {
        $rows = $this->sheets->getRows($sheetId, 'Outreach_Log');
        $created = 0;

        foreach ($rows as $row) {
            $platform = strtolower(trim((string) ($row['Platform'] ?? '')));
            $handle = $this->normalizeHandle((string) ($row['Handle'] ?? ''));
            $profileId = $creatorProfileMap[$platform . '|' . strtolower($handle)] ?? null;

            $event = OutreachEvent::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'external_event_key' => trim((string) ($row['Event_ID'] ?? '')) ?: 'sheet-row:' . (int) ($row['_row_number'] ?? 0),
                ],
                [
                    'creator_profile_id' => $profileId,
                    'platform' => $platform ?: null,
                    'handle' => $handle ?: null,
                    'channel' => trim((string) ($row['Channel'] ?? '')) ?: null,
                    'event_type' => trim((string) ($row['Event_Type'] ?? 'UNKNOWN')),
                    'sender_account' => trim((string) ($row['Sender_Account'] ?? '')) ?: null,
                    'sent_at' => $this->parseDateTime($row['Sent_At'] ?? null),
                    'status' => trim((string) ($row['Status'] ?? '')) ?: null,
                    'url' => trim((string) ($row['URL'] ?? '')) ?: null,
                    'notes' => trim((string) ($row['Notes'] ?? '')) ?: null,
                    'metadata' => ['source_reference' => 'Outreach_Log:' . (int) ($row['_row_number'] ?? 0)],
                ],
            );

            if ($event->wasRecentlyCreated) {
                $created++;
            }
        }

        return [
            'rows_read' => count($rows),
            'total' => OutreachEvent::where('project_id', $project->id)->count(),
            'created' => $created,
        ];
    }

    private function importDiscoverySheets(Project $project, string $sheetId): array
    {
        $sheetConfig = [
            'instagram' => ['raw_sheet' => 'Instagram_Posts_Raw', 'timestamp_column' => 'timestamp', 'handle_column' => 'ownerUsername', 'profile_base' => 'https://www.instagram.com/', 'post_url_column' => 'url'],
            'tiktok' => ['raw_sheet' => 'TikTok_Posts_Raw', 'timestamp_column' => 'createTimeISO', 'handle_column' => 'authorMeta.name', 'profile_base' => 'https://www.tiktok.com/@', 'post_url_column' => 'webVideoUrl'],
        ];

        $summary = [];

        foreach ($sheetConfig as $platform => $config) {
            $rows = $this->sheets->getRows($sheetId, $config['raw_sheet']);

            $run = DiscoveryRun::create([
                'project_id' => $project->id,
                'platform' => $platform,
                'provider' => 'legacy_sheet_import',
                'status' => 'imported',
                'current_step' => 'imported_snapshot',
                'request_payload' => ['sheet' => $config['raw_sheet']],
                'result_payload' => ['rows_read' => count($rows)],
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $createdItems = 0;

            foreach ($rows as $row) {
                $handle = $this->normalizeHandle((string) ($row[$config['handle_column']] ?? ''));
                $postUrl = trim((string) ($row[$config['post_url_column']] ?? ''));
                $caption = trim((string) ($row['caption'] ?? $row['text'] ?? ''));

                if ($handle === '' && $postUrl === '' && $caption === '') {
                    continue;
                }

                $profileUrl = $handle !== '' ? $config['profile_base'] . ltrim($handle, '@') . ($platform === 'instagram' ? '/' : '') : null;
                $hashtags = $this->parseHashtags($row['hashtags'] ?? null, $caption);
                $metrics = array_filter([
                    'likes' => $this->nullableInt($row['likesCount'] ?? $row['diggCount'] ?? null),
                    'comments' => $this->nullableInt($row['commentsCount'] ?? $row['commentCount'] ?? null),
                    'views' => $this->nullableInt($row['playCount'] ?? null),
                    'shares' => $this->nullableInt($row['shareCount'] ?? null),
                    'saves' => $this->nullableInt($row['collectCount'] ?? null),
                ], fn ($value) => $value !== null);

                $item = DiscoveryItem::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'platform' => $platform,
                        'post_url' => $postUrl !== '' ? $postUrl : ('sheet-row:' . (int) ($row['_row_number'] ?? 0)),
                    ],
                    [
                        'discovery_run_id' => $run->id,
                        'handle' => $handle ?: null,
                        'username' => $handle !== '' ? ltrim($handle, '@') : null,
                        'full_name' => trim((string) ($row['ownerFullName'] ?? '')) ?: null,
                        'profile_url' => $profileUrl,
                        'caption' => $caption ?: null,
                        'hashtags' => $hashtags,
                        'metrics' => $metrics,
                        'duplicate_key' => strtolower($platform . '|' . ($handle ?: $postUrl)),
                        'recommended_action' => trim((string) ($row['Recommended_Action'] ?? '')) ?: null,
                        'raw_payload' => $row,
                        'discovered_at' => $this->parseDateTime($row[$config['timestamp_column']] ?? null),
                    ],
                );

                if ($item->wasRecentlyCreated) {
                    $createdItems++;
                }
            }

            $summary[$platform] = [
                'rows_read' => count($rows),
                'items_total' => DiscoveryItem::where('project_id', $project->id)->where('platform', $platform)->count(),
                'items_created' => $createdItems,
                'run_id' => $run->id,
            ];
        }

        return $summary;
    }

    private function importEnrichmentSnapshots(Project $project, string $sheetId): array
    {
        $sheets = [
            'instagram' => 'Instagram_Profile_Enriched',
            'tiktok' => 'TikTok_Profile_Enriched',
        ];

        $summary = [];

        foreach ($sheets as $platform => $sheetName) {
            $rows = $this->sheets->getRows($sheetId, $sheetName);

            $job = EnrichmentJob::create([
                'project_id' => $project->id,
                'platform' => $platform,
                'provider' => 'legacy_sheet_import',
                'status' => 'imported',
                'input_urls' => array_values(array_filter(array_map(fn (array $row) => trim((string) ($row['profile_url'] ?? $row['input_url'] ?? '')) ?: null, $rows))),
                'request_payload' => ['sheet' => $sheetName],
                'result_payload' => ['rows_read' => count($rows)],
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $summary[$platform] = [
                'rows_read' => count($rows),
                'job_id' => $job->id,
            ];
        }

        return $summary;
    }

    private function creatorIdentityKey(array $row): string
    {
        $notes = (string) ($row['Notes'] ?? '');
        $explicitIdentity = $this->extractTaggedValue($notes, 'creator_identity_id');
        if ($explicitIdentity) {
            return 'sheet_identity:' . strtolower($explicitIdentity);
        }

        $email = strtolower(trim((string) ($row['Contact_Email'] ?? '')));
        if ($email !== '') {
            return 'email:' . $email;
        }

        $name = strtolower(trim((string) ($row['Name'] ?? '')));
        if ($name !== '') {
            return 'name:' . $name;
        }

        return 'profile:' . strtolower(trim((string) ($row['Platform'] ?? ''))) . '|' . strtolower($this->normalizeHandle((string) ($row['Handle'] ?? '')));
    }

    private function extractTaggedValue(string $text, string $key): ?string
    {
        if (preg_match('/(?:^|[;|\s])' . preg_quote($key, '/') . '=([^;|]+)/i', $text, $matches)) {
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

        if (!str_contains($text, '|| META:')) {
            return $result;
        }

        [$trigger, $metaJson] = explode('|| META:', $text, 2);
        $decoded = json_decode(trim($metaJson), true);

        if (!is_array($decoded)) {
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

    private function parseHashtags(mixed $sheetValue, string $caption = ''): array
    {
        if (is_array($sheetValue)) {
            return array_values(array_unique(array_map(fn ($value) => ltrim(trim((string) $value), '#'), $sheetValue)));
        }

        $value = trim((string) ($sheetValue ?? ''));
        if ($value !== '') {
            $split = preg_split('/[,\s]+/', str_replace(['[', ']', '"', "'"], '', $value)) ?: [];
            $tags = array_values(array_filter(array_map(fn ($item) => ltrim(trim((string) $item), '#'), $split)));

            if ($tags !== []) {
                return array_values(array_unique($tags));
            }
        }

        preg_match_all('/#([A-Za-z0-9_]+)/', $caption, $matches);

        return array_values(array_unique($matches[1] ?? []));
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
        if ($normalized === '' || !is_numeric($normalized)) {
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
        if ($normalized === '' || !is_numeric($normalized)) {
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

        return str_starts_with($handle, '@') ? $handle : '@' . $handle;
    }
}
