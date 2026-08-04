<?php

namespace App\Services;

use App\Exceptions\CrmImportValidationException;
use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\CrmImportBatch;
use App\Models\CrmImportBatchItem;
use App\Models\OutreachEvent;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CrmFileImportService
{
    private const FIELD_ALIASES = [
        'platform' => ['platform', 'social_platform', 'channel', 'network', 'social_network'],
        'handle' => ['handle', 'username', 'user_name', 'creator_username', 'creator_handle', 'creator', 'profile', 'account', 'ig_account', 'instagram_handle', 'tiktok_handle'],
        'name' => ['name', 'full_name', 'creator_name', 'display_name'],
        'email' => ['contact_email', 'email', 'creator_email', 'business_email', 'mail'],
        'followers' => ['followers', 'followers_count', 'follower_count', 'audience_size'],
        'engagement_rate' => ['engagement_rate_%', 'engagement_rate', 'engagement', 'er'],
        'status' => ['status', 'stage', 'lifecycle_state'],
        'value_score' => ['value_score', 'score', 'priority_score'],
        'value_bar' => ['value_bar', 'value_tier', 'tier'],
        'niche' => ['niche_category', 'niche', 'category', 'vertical'],
        'country' => ['country', 'creator_country'],
        'city' => ['city', 'creator_city'],
        'language' => ['primary_language', 'language'],
        'notes' => ['notes', 'comment', 'comments'],
        'profile_url' => ['profile_url', 'profile_link', 'social_url', 'instagram_url', 'tiktok_url', 'url', 'dm_link', 'dm_url', 'link'],
        'avatar_url' => ['profile_pic_url', 'avatar_url', 'profile_picture'],
        'preferred_channel' => ['preferred_channel', 'preferred_contact_channel'],
        'duplicate_flag' => ['duplicate_flag', 'duplicate'],
        'accepted' => ['accepted_(y/n)', 'accepted', 'accepted_flag'],
        'follow_up_needed' => ['follow_up_needed_(y/n)', 'follow_up_needed'],
        'last_contacted_at' => ['last_contacted_at', 'last_contacted', 'contacted_at', 'last_outreach_at', 'last_message_at'],
        'last_reply_at' => ['last_reply_at', 'last_reply', 'replied_at', 'responded_at', 'response_date'],
        'next_follow_up_at' => ['next_follow_up_at', 'next_follow_up', 'follow_up_due_at', 'follow_up_date', 'next_action_at'],
        'conversation_url' => ['conversation_url', 'thread_url', 'message_thread_url', 'conversation_link'],
        'outreach_channel' => ['outreach_channel', 'conversation_channel', 'contact_channel', 'existing_outreach_channel'],
        'conversation_summary' => ['conversation_summary', 'relationship_summary', 'conversation_notes', 'latest_context'],
        'latest_outbound_message' => ['latest_outbound_message', 'last_outbound_message', 'last_message_sent', 'latest_sent_message'],
        'latest_inbound_message' => ['latest_inbound_message', 'last_inbound_message', 'last_reply_text', 'latest_reply'],
    ];

    private const CANONICAL_LIFECYCLE_STATES = [
        'imported',
        'approved_for_outreach',
        'contacted',
        'replied',
        'follow_up',
        'negotiating',
        'accepted',
        'declined',
        'lost',
        'archived',
        'won',
    ];

    public function __construct(
        private ProjectResolverService $projects,
        private AvatarCacheService $avatarCache,
        private CreatorRelationshipTimelineService $relationshipTimeline,
    ) {}

    public function previewCreatorsCsv(UploadedFile $file, array $mapping = [], array $options = []): array
    {
        $data = $this->readCsvData($file);
        $headers = array_map(fn (array $header) => $header['label'], $data['headers']);
        $suggestedMapping = $this->suggestMapping($headers);
        $mapping = $this->normalizeMapping($mapping !== [] ? $mapping : $suggestedMapping);

        return [
            'headers' => $headers,
            'fields' => $this->importFields(),
            'suggestedMapping' => $suggestedMapping,
            'sampleRows' => array_slice($data['displayRows'], 0, 5),
            'rowsRead' => count($data['rows']),
            'workflow' => $this->analyzeWorkflow($data, $mapping, $options),
        ];
    }

    public function importCreatorsCsv(string $workbookId, UploadedFile $file, array $mapping = [], array $options = []): array
    {
        $project = $this->projects->resolveByWorkbookId($workbookId);
        $data = $this->readCsvData($file);
        $rows = $data['rows'];
        $mapping = $this->normalizeMapping($mapping);
        $defaultPlatform = $this->normalizePlatform((string) ($options['defaultPlatform'] ?? ''));
        $stageMapping = $this->normalizeStageMapping((array) ($options['stageMapping'] ?? []));
        $workflowAnalysis = $this->analyzeWorkflow($data, $mapping, ['stageMapping' => $stageMapping]);
        if ((int) ($workflowAnalysis['unknownStageCount'] ?? 0) > 0) {
            $unknownStages = collect((array) ($workflowAnalysis['stages'] ?? []))
                ->where('requiresMapping', true)
                ->pluck('source')
                ->take(5)
                ->implode(', ');
            throw new CrmImportValidationException('Map every workflow stage before importing. Still unmapped: '.$unknownStages.'.');
        }
        $pauseWorkflow = filter_var($options['pauseWorkflow'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $trackBatch = filter_var($options['trackBatch'] ?? $pauseWorkflow, FILTER_VALIDATE_BOOLEAN);
        $assignedUserId = trim((string) ($options['assignedUserId'] ?? '')) ?: null;
        $missingNextActionStrategy = in_array(($options['missingNextActionStrategy'] ?? ''), ['schedule', 'keep_paused'], true)
            ? (string) $options['missingNextActionStrategy']
            : 'keep_paused';
        $missingNextActionDays = max(1, min(90, (int) ($options['missingNextActionDays'] ?? 3)));
        $createdCreators = 0;
        $createdProfiles = 0;
        $updatedProfiles = 0;
        $skipped = 0;
        $errorCount = 0;
        $errors = [];
        $avatarUrls = [];
        $profileIds = [];
        $batch = null;

        DB::transaction(function () use ($project, $rows, $file, $mapping, $defaultPlatform, $stageMapping, $pauseWorkflow, $trackBatch, $assignedUserId, $missingNextActionStrategy, $missingNextActionDays, $options, &$createdCreators, &$createdProfiles, &$updatedProfiles, &$skipped, &$errorCount, &$errors, &$avatarUrls, &$profileIds, &$batch): void {
            if ($trackBatch) {
                $batch = CrmImportBatch::query()->create([
                    'workspace_id' => (string) $project->workspace_id,
                    'project_id' => $project->id,
                    'created_by_user_id' => trim((string) ($options['createdByUserId'] ?? '')) ?: null,
                    'original_filename' => $file->getClientOriginalName(),
                    'status' => $pauseWorkflow ? 'imported_paused' : 'activated',
                    'row_count' => count($rows),
                    'settings' => [
                        'stageMapping' => $stageMapping,
                        'assignedUserId' => $assignedUserId,
                        'missingNextActionStrategy' => $missingNextActionStrategy,
                        'missingNextActionDays' => $missingNextActionDays,
                    ],
                    'activated_at' => $pauseWorkflow ? null : now(),
                ]);
            }

            foreach ($rows as $index => $row) {
                $rawPlatform = $this->value($row, 'platform', $mapping);
                $rawHandle = $this->value($row, 'handle', $mapping);
                $profileUrlValue = $this->value($row, 'profile_url', $mapping);
                $platform = $this->resolvePlatform($rawPlatform, $rawHandle, $profileUrlValue, $defaultPlatform);
                $handle = $this->normalizeHandle($rawHandle !== '' ? $rawHandle : $profileUrlValue, $platform);
                $rawStatus = $this->value($row, 'status', $mapping);
                $resolvedLifecycle = $rawStatus !== '' ? $this->resolveImportedLifecycle($rawStatus, $stageMapping) : null;

                if ($platform === '' || $handle === '' || ($rawStatus !== '' && $resolvedLifecycle === null)) {
                    $skipped++;
                    $errorCount++;
                    if (count($errors) < 200) {
                        $errors[] = [
                            'rowNumber' => (int) ($row['__row_number'] ?? ($index + 2)),
                            'reason' => $platform === ''
                                ? ($rawPlatform !== '' ? 'Unsupported platform value.' : 'Platform could not be inferred. Choose a default platform or map a profile URL.')
                                : ($handle === ''
                                    ? 'Handle could not be read. Map a handle or profile URL column.'
                                    : 'The stage "'.$rawStatus.'" must be mapped before importing.'),
                            'platform' => $rawPlatform,
                            'handle' => $rawHandle,
                            'profileUrl' => $profileUrlValue,
                            'row' => $this->errorRowPreview((array) ($row['__display'] ?? [])),
                        ];
                    }

                    continue;
                }

                $email = $this->nullableString($this->value($row, 'email', $mapping));
                $displayName = $this->nullableString($this->value($row, 'name', $mapping));
                $identityKey = $this->creatorIdentityKey($platform, $handle, $email, $displayName);

                $profile = CreatorProfile::query()
                    ->where('project_id', $project->id)
                    ->where('platform', $platform)
                    ->whereRaw('LOWER(handle) = ?', [$handle])
                    ->first();

                $creator = $profile?->creator_id
                    ? Creator::query()->find($profile->creator_id)
                    : null;

                $creator ??= Creator::query()
                    ->where('project_id', $project->id)
                    ->where('external_identity_key', $identityKey)
                    ->first();

                if (! $creator && $email) {
                    $creator = Creator::query()
                        ->where('project_id', $project->id)
                        ->where('primary_email', $email)
                        ->first();
                }

                if (! $creator) {
                    $creator = new Creator([
                        'project_id' => $project->id,
                        'external_identity_key' => $identityKey,
                    ]);
                    $createdCreators++;
                }

                $creatorBefore = $creator->exists ? $this->creatorSnapshot($creator) : null;

                $creator->display_name = $displayName ?: ($creator->display_name ?: ltrim($handle, '@'));
                $creator->primary_email = $email ?: $creator->primary_email;
                $creator->external_identity_key = $this->creatorIdentityKey($platform, $handle, $creator->primary_email, $creator->display_name);
                $creator->country = $this->nullableString($this->value($row, 'country', $mapping)) ?: $creator->country;
                $creator->city = $this->nullableString($this->value($row, 'city', $mapping)) ?: $creator->city;
                $creator->primary_language = $this->nullableString($this->value($row, 'language', $mapping)) ?: $creator->primary_language;
                $creator->niche_category = $this->nullableString($this->value($row, 'niche', $mapping)) ?: $creator->niche_category;
                $conversationSummary = $this->nullableString($this->value($row, 'conversation_summary', $mapping));
                $creator->notes = $conversationSummary
                    ? $this->mergeNotes((string) ($creator->notes ?? ''), $conversationSummary)
                    : ($this->nullableString($this->value($row, 'notes', $mapping)) ?: $creator->notes);
                $customFields = $this->customFieldsForRow($row, $mapping);
                $existingMetadata = (array) ($creator->metadata ?? []);
                $creator->metadata = array_filter(array_merge((array) ($creator->metadata ?? []), [
                    'custom_fields' => array_merge((array) ($existingMetadata['custom_fields'] ?? []), $customFields),
                    'last_file_import' => [
                        'filename' => $file->getClientOriginalName(),
                        'row_number' => (int) ($row['__row_number'] ?? ($index + 2)),
                    ],
                ]));
                $creator->save();

                $wasNewProfile = ! $profile;
                $profileBefore = $profile?->exists ? $this->profileSnapshot($profile) : null;
                $profile ??= new CreatorProfile([
                    'project_id' => $project->id,
                    'platform' => $platform,
                    'handle' => $handle,
                ]);

                $profile->creator_id = $creator->id;
                $profile->platform = $platform;
                $profile->handle = $handle;
                $profile->username = ltrim($handle, '@');
                $profileUrl = $this->nullableString($profileUrlValue) ?: $this->profileUrlFromValue($rawHandle);
                $profile->profile_url = $profileUrl ?: $profile->profile_url;
                $profile->dm_link = $profileUrl ?: $profile->dm_link;
                $profile->profile_pic_url = $this->nullableString($this->value($row, 'avatar_url', $mapping)) ?: $profile->profile_pic_url;
                if ((string) ($profile->profile_pic_url ?? '') !== '') {
                    $avatarUrls[] = (string) $profile->profile_pic_url;
                }
                $this->applyImportedWorkflowState(
                    $profile,
                    $row,
                    $mapping,
                    $wasNewProfile,
                    $resolvedLifecycle,
                    $missingNextActionStrategy,
                    $missingNextActionDays,
                );
                $profile->followers_count = $this->nullableInt($this->value($row, 'followers', $mapping)) ?? $profile->followers_count;
                $profile->engagement_rate_pct = $this->nullableFloat($this->value($row, 'engagement_rate', $mapping)) ?? $profile->engagement_rate_pct;
                $profile->preferred_channel = $this->nullableString($this->value($row, 'preferred_channel', $mapping)) ?: $profile->preferred_channel;
                $profile->value_score = $this->nullableInt($this->value($row, 'value_score', $mapping)) ?? $profile->value_score;
                $profile->value_bar = $this->nullableString($this->value($row, 'value_bar', $mapping)) ?: $profile->value_bar;
                $profile->duplicate_flag = $this->nullableString($this->value($row, 'duplicate_flag', $mapping)) ?: $profile->duplicate_flag;
                $profile->accepted_flag = $this->parseYesNo($this->value($row, 'accepted', $mapping)) ?? (bool) $profile->accepted_flag;
                $profile->follow_up_needed = $this->parseYesNo($this->value($row, 'follow_up_needed', $mapping)) ?? (bool) $profile->follow_up_needed;
                $profile->source_provider = 'file_upload';
                $profile->import_batch_id = $batch?->id;
                $profile->assigned_user_id = $assignedUserId ?: $profile->assigned_user_id;
                $profile->workflow_paused_at = $pauseWorkflow ? now() : null;
                $profile->source_reference = 'csv:'.$file->getClientOriginalName().':'.((int) ($row['__row_number'] ?? ($index + 2)));
                $profile->source_metadata = array_filter(array_merge((array) ($profile->source_metadata ?? []), [
                    'import_filename' => $file->getClientOriginalName(),
                    'import_row_number' => (int) ($row['__row_number'] ?? ($index + 2)),
                    'imported_from' => 'crm_file_upload',
                ]));
                $profile->last_synced_at = now();
                $profile->save();
                $profileIds[] = (string) $profile->id;

                if ($batch) {
                    CrmImportBatchItem::query()->firstOrCreate(
                        ['batch_id' => $batch->id, 'creator_profile_id' => $profile->id],
                        [
                            'creator_id' => $creator->id,
                            'action' => $wasNewProfile ? 'created' : 'updated',
                            'creator_before' => $creatorBefore,
                            'profile_before' => $profileBefore,
                        ],
                    );
                }

                $this->recordImportedConversation($project, $profile, $row, $mapping, $batch?->id);

                if ($wasNewProfile) {
                    $createdProfiles++;
                } else {
                    $updatedProfiles++;
                }
            }

            if ($batch) {
                $batch->summary = [
                    'rowsRead' => count($rows),
                    'createdCreators' => $createdCreators,
                    'createdProfiles' => $createdProfiles,
                    'updatedProfiles' => $updatedProfiles,
                    'skipped' => $skipped,
                    'errorCount' => $errorCount,
                    'pausedProfiles' => $pauseWorkflow ? count(array_unique($profileIds)) : 0,
                ];
                $batch->save();
            }
        });
        $this->avatarCache->warmManyAfterResponse($avatarUrls, 25);

        return [
            'projectId' => $project->id,
            'rowsRead' => count($rows),
            'createdCreators' => $createdCreators,
            'createdProfiles' => $createdProfiles,
            'updatedProfiles' => $updatedProfiles,
            'skipped' => $skipped,
            'errorCount' => $errorCount,
            'errors' => $errors,
            'errorsTruncated' => $errorCount > count($errors),
            'totalProfiles' => CreatorProfile::query()->where('project_id', $project->id)->count(),
            'profileIds' => array_values(array_unique($profileIds)),
            'batchId' => $batch?->id,
            'batchStatus' => $batch?->status,
            'pausedProfiles' => $pauseWorkflow ? count(array_unique($profileIds)) : 0,
        ];
    }

    private function analyzeWorkflow(array $data, array $mapping, array $options): array
    {
        $stageMapping = $this->normalizeStageMapping((array) ($options['stageMapping'] ?? []));
        $stageCounts = [];
        $missingNextAction = 0;
        $rowsWithConversation = 0;

        foreach ($data['rows'] as $row) {
            $rawStatus = trim($this->value($row, 'status', $mapping));
            if ($rawStatus !== '') {
                $key = $this->normalizeStageKey($rawStatus);
                if (! isset($stageCounts[$key])) {
                    $stageCounts[$key] = ['source' => $rawStatus, 'count' => 0];
                }
                $stageCounts[$key]['count']++;
            }

            $resolved = $this->resolveImportedLifecycle($rawStatus, $stageMapping);
            if (in_array($resolved, ['contacted', 'follow_up', 'negotiating', 'accepted'], true)
                && $this->value($row, 'next_follow_up_at', $mapping) === '') {
                $missingNextAction++;
            }

            if ($this->value($row, 'conversation_summary', $mapping) !== ''
                || $this->value($row, 'latest_outbound_message', $mapping) !== ''
                || $this->value($row, 'latest_inbound_message', $mapping) !== '') {
                $rowsWithConversation++;
            }
        }

        $stages = collect($stageCounts)->map(function (array $stage, string $key) use ($stageMapping) {
            $resolved = $this->resolveImportedLifecycle($stage['source'], $stageMapping);

            return [
                'source' => $stage['source'],
                'key' => $key,
                'count' => $stage['count'],
                'mappedTo' => $resolved,
                'requiresMapping' => $resolved === null,
            ];
        })->values()->all();

        $mappedHeaders = array_values($mapping);
        $unmappedHeaders = collect($data['headers'])
            ->filter(fn (array $header) => ! in_array($header['key'], $mappedHeaders, true))
            ->map(fn (array $header) => $header['label'])
            ->values()
            ->all();

        return [
            'stages' => $stages,
            'unknownStageCount' => collect($stages)->where('requiresMapping', true)->sum('count'),
            'missingNextActionCount' => $missingNextAction,
            'rowsWithConversationContext' => $rowsWithConversation,
            'unmappedHeaders' => $unmappedHeaders,
            'canonicalStages' => self::CANONICAL_LIFECYCLE_STATES,
        ];
    }

    private function normalizeStageMapping(array $mapping): array
    {
        $normalized = [];
        foreach ($mapping as $source => $target) {
            $target = $this->normalizeLifecycleState((string) $target);
            if (in_array($target, self::CANONICAL_LIFECYCLE_STATES, true)) {
                $normalized[$this->normalizeStageKey((string) $source)] = $target;
            }
        }

        return $normalized;
    }

    private function resolveImportedLifecycle(string $rawStatus, array $stageMapping): ?string
    {
        $rawStatus = trim($rawStatus);
        if ($rawStatus === '') {
            return 'imported';
        }

        $key = $this->normalizeStageKey($rawStatus);
        if (isset($stageMapping[$key])) {
            return $stageMapping[$key];
        }

        $normalized = $this->normalizeLifecycleState($rawStatus);

        return in_array($normalized, self::CANONICAL_LIFECYCLE_STATES, true) ? $normalized : null;
    }

    private function normalizeStageKey(string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function customFieldsForRow(array $row, array $mapping): array
    {
        $mappedHeaders = array_values($mapping);
        $custom = [];
        foreach ($row as $header => $value) {
            if (Str::startsWith((string) $header, '__') || in_array($header, $mappedHeaders, true)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $custom[(string) $header] = Str::limit($value, 2000, '…');
            }
        }

        return $custom;
    }

    private function mergeNotes(string $existing, string $conversationSummary): string
    {
        $existing = trim($existing);
        $summary = 'Imported conversation summary: '.trim($conversationSummary);
        if ($existing === '') {
            return $summary;
        }
        if (str_contains($existing, $summary)) {
            return $existing;
        }

        return $existing."\n\n".$summary;
    }

    private function creatorSnapshot(Creator $creator): array
    {
        return collect($creator->attributesToArray())
            ->except(['id', 'project_id', 'created_at', 'updated_at'])
            ->all();
    }

    private function profileSnapshot(CreatorProfile $profile): array
    {
        return collect($profile->attributesToArray())
            ->except(['id', 'project_id', 'created_at', 'updated_at'])
            ->all();
    }

    private function recordImportedConversation($project, CreatorProfile $profile, array $row, array $mapping, ?string $batchId): void
    {
        $channel = $this->normalizeOutreachChannel($this->value($row, 'outreach_channel', $mapping))
            ?: $this->normalizeOutreachChannel($this->value($row, 'preferred_channel', $mapping))
            ?: (string) $profile->platform;
        $messages = [
            ['field' => 'latest_outbound_message', 'type' => 'MESSAGE_SENT', 'date' => $this->nullableDate($this->value($row, 'last_contacted_at', $mapping))],
            ['field' => 'latest_inbound_message', 'type' => 'CREATOR_REPLY', 'date' => $this->nullableDate($this->value($row, 'last_reply_at', $mapping))],
        ];

        foreach ($messages as $message) {
            $text = trim($this->value($row, $message['field'], $mapping));
            if ($text === '') {
                continue;
            }

            $event = OutreachEvent::query()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'external_event_key' => $batchId
                        ? 'crm-import:'.$batchId.':'.$profile->id.':'.$message['field']
                        : (string) Str::uuid(),
                ],
                [
                    'creator_profile_id' => $profile->id,
                    'platform' => $profile->platform,
                    'handle' => $profile->handle,
                    'channel' => $channel,
                    'event_type' => $message['type'],
                    'sent_at' => $message['date'] ?: now(),
                    'status' => 'IMPORTED',
                    'url' => $profile->conversation_url,
                    'notes' => 'Imported from an existing CRM.',
                    'metadata' => array_filter([
                        'message_text' => Str::limit($text, 10000, '…'),
                        'message_direction' => $message['type'] === 'CREATOR_REPLY' ? 'inbound' : 'outbound',
                        'import_batch_id' => $batchId,
                    ]),
                ],
            );

            $this->relationshipTimeline->recordOutreachEvent($event, $project);
        }
    }

    private function readCsvData(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $path || ! is_readable($path)) {
            throw new RuntimeException('Uploaded file could not be read.');
        }

        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('Uploaded file could not be opened.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false || trim(preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? '') === '') {
                throw new CrmImportValidationException('The CSV file is empty. Add a header row and at least one creator.');
            }
            $delimiter = $this->detectDelimiter($firstLine);
            rewind($handle);

            $headers = fgetcsv($handle, 0, $delimiter);
            if (! is_array($headers) || $headers === []) {
                throw new CrmImportValidationException('The CSV header row could not be read.');
            }

            $headerMeta = array_map(fn ($header) => [
                'label' => trim((string) $header),
                'key' => $this->normalizeHeader((string) $header),
            ], $headers);
            $normalizedHeaders = array_column($headerMeta, 'key');
            if (array_filter($normalizedHeaders) === []) {
                throw new CrmImportValidationException('The CSV header row is empty. Add named columns before importing.');
            }
            $duplicateHeaders = array_keys(array_filter(array_count_values(array_filter($normalizedHeaders)), fn (int $count) => $count > 1));
            if ($duplicateHeaders !== []) {
                throw new CrmImportValidationException('Duplicate CSV columns were found after normalization: '.implode(', ', $duplicateHeaders).'. Rename them and try again.');
            }
            $rows = [];
            $displayRows = [];
            $rowLimit = 5000;
            $rowNumber = 1;

            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;
                if (count($rows) >= $rowLimit) {
                    throw new CrmImportValidationException("CSV import limit is {$rowLimit} rows. Split the file into smaller imports and try again.");
                }

                if ($values === [null] || $values === []) {
                    continue;
                }

                $row = [];
                $displayRow = [];
                foreach ($normalizedHeaders as $index => $header) {
                    if ($header === '') {
                        continue;
                    }
                    $value = trim((string) ($values[$index] ?? ''));
                    $row[$header] = $value;
                    $displayRow[$headerMeta[$index]['label'] ?: $header] = $value;
                }

                if (array_filter($row, fn ($value) => trim((string) $value) !== '') !== []) {
                    $row['__row_number'] = $rowNumber;
                    $row['__display'] = $displayRow;
                    $rows[] = $row;
                    if (count($displayRows) < 5) {
                        $displayRows[] = $displayRow;
                    }
                }
            }

            if ($rows === []) {
                throw new CrmImportValidationException('The CSV contains headers but no creator rows.');
            }

            return [
                'headers' => $headerMeta,
                'rows' => $rows,
                'displayRows' => $displayRows,
            ];
        } finally {
            fclose($handle);
        }
    }

    private function detectDelimiter(string $line): string
    {
        $candidates = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = Str::lower(trim($header));
        $header = str_replace([' ', '-', '.', '/', '\\'], '_', $header);
        $header = preg_replace('/_+/', '_', $header) ?? $header;

        return trim($header, '_');
    }

    private function importFields(): array
    {
        return [
            ['key' => 'platform', 'label' => 'Platform', 'required' => false],
            ['key' => 'handle', 'label' => 'Handle', 'required' => false],
            ['key' => 'name', 'label' => 'Name', 'required' => false],
            ['key' => 'email', 'label' => 'Email', 'required' => false],
            ['key' => 'followers', 'label' => 'Followers', 'required' => false],
            ['key' => 'engagement_rate', 'label' => 'Engagement rate', 'required' => false],
            ['key' => 'status', 'label' => 'Status', 'required' => false],
            ['key' => 'value_score', 'label' => 'Value score', 'required' => false],
            ['key' => 'niche', 'label' => 'Niche', 'required' => false],
            ['key' => 'country', 'label' => 'Country', 'required' => false],
            ['key' => 'city', 'label' => 'City', 'required' => false],
            ['key' => 'profile_url', 'label' => 'Profile URL', 'required' => false],
            ['key' => 'language', 'label' => 'Language', 'required' => false],
            ['key' => 'avatar_url', 'label' => 'Avatar URL', 'required' => false],
            ['key' => 'preferred_channel', 'label' => 'Preferred channel', 'required' => false],
            ['key' => 'last_contacted_at', 'label' => 'Last contacted', 'required' => false],
            ['key' => 'last_reply_at', 'label' => 'Last reply', 'required' => false],
            ['key' => 'next_follow_up_at', 'label' => 'Next follow-up', 'required' => false],
            ['key' => 'conversation_url', 'label' => 'Conversation URL', 'required' => false],
            ['key' => 'outreach_channel', 'label' => 'Existing outreach channel', 'required' => false],
            ['key' => 'conversation_summary', 'label' => 'Conversation summary', 'required' => false],
            ['key' => 'latest_outbound_message', 'label' => 'Latest sent message', 'required' => false],
            ['key' => 'latest_inbound_message', 'label' => 'Latest creator reply', 'required' => false],
            ['key' => 'notes', 'label' => 'Notes', 'required' => false],
        ];
    }

    private function suggestMapping(array $headers): array
    {
        $mapping = [];
        $normalizedHeaders = [];
        foreach ($headers as $header) {
            $normalizedHeaders[$this->normalizeHeader((string) $header)] = (string) $header;
        }

        foreach (self::FIELD_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $normalizedAlias = $this->normalizeHeader($alias);
                if (isset($normalizedHeaders[$normalizedAlias])) {
                    $mapping[$field] = $normalizedHeaders[$normalizedAlias];
                    break;
                }
            }
        }

        return $mapping;
    }

    private function normalizeMapping(array $mapping): array
    {
        $normalized = [];
        foreach ($mapping as $field => $header) {
            $field = (string) $field;
            $header = trim((string) $header);
            if ($header !== '' && $header !== '__skip__' && isset(self::FIELD_ALIASES[$field])) {
                $normalized[$field] = $this->normalizeHeader($header);
            }
        }

        return $normalized;
    }

    private function value(array $row, string $field, array $mapping): string
    {
        if (isset($mapping[$field]) && array_key_exists($mapping[$field], $row)) {
            return trim((string) $row[$mapping[$field]]);
        }

        foreach (self::FIELD_ALIASES[$field] ?? [] as $alias) {
            $header = $this->normalizeHeader($alias);
            if (array_key_exists($header, $row) && trim((string) $row[$header]) !== '') {
                return trim((string) $row[$header]);
            }
        }

        return '';
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = Str::lower(trim($platform));

        return match ($platform) {
            'instagram', 'insta', 'ig', 'instagram reels', 'instagram_reels' => 'instagram',
            'tiktok', 'tik tok', 'tik_tok', 'tt' => 'tiktok',
            'email', 'e-mail', 'mail' => 'email',
            default => '',
        };
    }

    private function resolvePlatform(string $rawPlatform, string $rawHandle, string $profileUrl, string $defaultPlatform): string
    {
        if (trim($rawPlatform) !== '') {
            return $this->normalizePlatform($rawPlatform);
        }

        $inferred = $this->inferPlatformFromUrl($profileUrl) ?: $this->inferPlatformFromUrl($rawHandle);

        return $inferred ?: $defaultPlatform;
    }

    private function inferPlatformFromUrl(string $value): string
    {
        $normalizedUrl = $this->normalizeProfileUrlValue($value);
        $host = Str::lower((string) parse_url($normalizedUrl ?: trim($value), PHP_URL_HOST));
        if ($host === '') {
            return '';
        }

        if ($host === 'instagram.com' || Str::endsWith($host, '.instagram.com')) {
            return 'instagram';
        }

        if ($host === 'tiktok.com' || Str::endsWith($host, '.tiktok.com')) {
            return 'tiktok';
        }

        return '';
    }

    private function normalizeHandle(string $handle, string $platform = ''): string
    {
        $handle = trim($handle);
        if ($handle === '') {
            return '';
        }

        if ($platform === 'email') {
            return filter_var($handle, FILTER_VALIDATE_EMAIL) ? Str::lower($handle) : '';
        }

        $normalizedUrl = $this->normalizeProfileUrlValue($handle);
        if ($normalizedUrl !== null) {
            $path = trim(rawurldecode((string) parse_url($normalizedUrl, PHP_URL_PATH)), '/');
            $segments = array_values(array_filter(explode('/', $path)));
            if ($platform === 'instagram' && isset($segments[0]) && ! in_array(Str::lower($segments[0]), ['p', 'reel', 'reels', 'stories', 'explore'], true)) {
                $handle = $segments[0];
            } elseif ($platform === 'tiktok' && isset($segments[0]) && Str::startsWith($segments[0], '@')) {
                $handle = $segments[0];
            } else {
                return '';
            }
        }

        $handle = trim(explode('?', explode('#', $handle)[0])[0], "/@ \t\n\r\0\x0B");
        if ($handle === '' || preg_match('/^[a-z0-9._-]{1,100}$/i', $handle) !== 1) {
            return '';
        }

        return '@'.Str::lower($handle);
    }

    private function errorRowPreview(array $row): array
    {
        $preview = [];
        foreach (array_slice($row, 0, 50, true) as $header => $value) {
            $preview[(string) $header] = Str::limit((string) $value, 500, '…');
        }

        return $preview;
    }

    private function profileUrlFromValue(string $value): ?string
    {
        return $this->normalizeProfileUrlValue($value);
    }

    private function normalizeProfileUrlValue(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('#^(?:www\.)?(?:instagram|tiktok)\.com/#i', $value) === 1) {
            $value = 'https://'.$value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    private function creatorIdentityKey(string $platform, string $handle, ?string $email, ?string $displayName): string
    {
        if ($email) {
            return 'email:'.Str::lower($email);
        }

        return 'profile:'.$platform.'|'.Str::lower($handle);
    }

    private function normalizeLifecycleState(string $status): string
    {
        $state = Str::lower(trim(str_replace([' ', '-'], '_', $status)));
        $state = preg_replace('/_+/', '_', $state) ?? $state;

        return match ($state) {
            '', 'new', 'not_contacted', 'uncontacted', 'prospect', 'imported' => 'imported',
            'approved', 'approved_for_outreach', 'ready_for_outreach', 'qualified', 'enriched' => 'approved_for_outreach',
            'contacted', 'email_sent', 'dm_sent', 'message_sent', 'awaiting_reply' => 'contacted',
            'replied', 'interested', 'response_received', 'responded' => 'replied',
            'follow_up', 'followup', 'follow_up_due', 'needs_follow_up' => 'follow_up',
            'negotiating', 'discussing_terms', 'in_negotiation' => 'negotiating',
            'accepted', 'confirmed', 'booked' => 'accepted',
            'declined', 'not_interested', 'rejected' => 'declined',
            'lost' => 'lost',
            'archived' => 'archived',
            'won', 'posted', 'completed' => 'won',
            default => $state,
        };
    }

    private function applyImportedWorkflowState(
        CreatorProfile $profile,
        array $row,
        array $mapping,
        bool $wasNewProfile,
        ?string $resolvedLifecycle = null,
        string $missingNextActionStrategy = 'keep_paused',
        int $missingNextActionDays = 3,
    ): void {
        $rawStatus = $this->value($row, 'status', $mapping);
        $lifecycle = $resolvedLifecycle ?? ($rawStatus !== ''
            ? $this->normalizeLifecycleState($rawStatus)
            : ($wasNewProfile ? 'imported' : $this->normalizeLifecycleState((string) ($profile->lifecycle_state ?: $profile->status))));

        $lastContactedAt = $this->nullableDate($this->value($row, 'last_contacted_at', $mapping));
        $lastReplyAt = $this->nullableDate($this->value($row, 'last_reply_at', $mapping));
        $nextFollowUpAt = $this->nullableDate($this->value($row, 'next_follow_up_at', $mapping));
        $conversationUrl = $this->nullableString($this->value($row, 'conversation_url', $mapping));
        $outreachChannel = $this->normalizeOutreachChannel($this->value($row, 'outreach_channel', $mapping))
            ?: $this->normalizeOutreachChannel($this->value($row, 'preferred_channel', $mapping));

        $profile->status = Str::upper($lifecycle);
        $profile->lifecycle_state = $lifecycle === 'follow_up' ? 'contacted' : $lifecycle;
        $profile->conversation_url = $conversationUrl ?: $profile->conversation_url;
        $profile->conversation_channel = $outreachChannel ?: $profile->conversation_channel;
        $profile->last_outreach_channel = $outreachChannel ?: $profile->last_outreach_channel;
        $profile->last_outreach_at = $lastContactedAt ?: $profile->last_outreach_at;
        $profile->dm_sent_at = $lastContactedAt ?: $profile->dm_sent_at;
        $profile->responded_at = $lastReplyAt ?: $profile->responded_at;
        $profile->follow_up_due_at = $nextFollowUpAt ?: $profile->follow_up_due_at;
        $profile->next_action_at = $nextFollowUpAt ?: $profile->next_action_at;

        $automationState = (array) ($profile->automation_state ?? []);
        $automationState['imported_workflow_state'] = $lifecycle;
        $automationState['imported_workflow_state_at'] = now()->toIso8601String();

        if (in_array($lifecycle, ['contacted', 'replied', 'follow_up', 'negotiating', 'accepted'], true)) {
            $profile->dm_sent_at ??= $lastContactedAt ?: now();
            $profile->last_outreach_at ??= $profile->dm_sent_at;
        }

        if (in_array($lifecycle, ['replied', 'negotiating', 'accepted'], true)) {
            $profile->responded_at ??= $lastReplyAt ?: now();
        }

        if ($lifecycle === 'follow_up') {
            $profile->follow_up_needed = true;
            if ($missingNextActionStrategy === 'schedule') {
                $profile->follow_up_due_at ??= $nextFollowUpAt ?: now()->addDays($missingNextActionDays);
            } elseif (! $profile->follow_up_due_at) {
                $automationState['migration_hold'] = true;
                $automationState['migration_hold_reason'] = 'missing_next_action_date';
            }
            $profile->next_action_at ??= $profile->follow_up_due_at;
        }

        if (in_array($lifecycle, ['contacted', 'negotiating', 'accepted'], true) && ! $nextFollowUpAt) {
            if ($missingNextActionStrategy === 'schedule') {
                $profile->next_action_at ??= now()->addDays($missingNextActionDays);
                if ($lifecycle === 'contacted') {
                    $profile->follow_up_needed = true;
                    $profile->follow_up_due_at ??= $profile->next_action_at;
                }
            } else {
                $automationState['migration_hold'] = true;
                $automationState['migration_hold_reason'] = 'missing_next_action_date';
            }
        }

        if ($lifecycle === 'accepted') {
            $profile->accepted_flag = true;
            $profile->follow_up_needed = false;
            $profile->follow_up_due_at = null;
        }

        if (in_array($lifecycle, ['declined', 'lost', 'archived', 'won'], true)) {
            $profile->follow_up_needed = false;
            $profile->follow_up_due_at = null;
            $profile->next_action_at = null;
        }

        $profile->automation_state = $automationState;
    }

    private function nullableDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeOutreachChannel(string $value): string
    {
        return match (Str::lower(trim($value))) {
            'instagram', 'ig', 'insta', 'dm', 'instagram dm' => 'instagram',
            'tiktok', 'tt', 'tik tok', 'tiktok dm' => 'tiktok',
            'email', 'e-mail', 'mail' => 'email',
            default => '',
        };
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(string $value): ?int
    {
        $value = Str::lower(trim($value));
        if ($value === '') {
            return null;
        }

        $multiplier = 1;
        if (preg_match('/([kmb])$/', $value, $match) === 1) {
            $multiplier = ['k' => 1000, 'm' => 1000000, 'b' => 1000000000][$match[1]];
            $value = substr($value, 0, -1);
        }

        $value = preg_replace('/[^0-9,.-]/', '', $value) ?? '';
        if ($multiplier > 1) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = preg_replace('/(?<=\d)[,.](?=\d{3}(?:\D|$))/', '', $value) ?? $value;
            $value = str_replace(',', '.', $value);
        }

        return $value !== '' && is_numeric($value) ? (int) round(((float) $value) * $multiplier) : null;
    }

    private function nullableFloat(string $value): ?float
    {
        $value = preg_replace('/[^0-9,.-]/', '', trim($value)) ?? '';
        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } else {
            $value = str_replace(',', '.', $value);
        }

        return $value !== '' && is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function parseYesNo(string $value): ?bool
    {
        $value = Str::lower(trim($value));
        if ($value === '') {
            return null;
        }

        if (in_array($value, ['y', 'yes', 'true', '1'], true)) {
            return true;
        }

        if (in_array($value, ['n', 'no', 'false', '0'], true)) {
            return false;
        }

        return null;
    }
}
