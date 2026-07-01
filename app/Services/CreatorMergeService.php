<?php

namespace App\Services;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreatorMergeService
{
    public const SOURCE_SHEETS = [
        'Instagram_Profile_Enriched',
        'TikTok_Profile_Enriched',
    ];

    public function __construct(
        private GoogleSheetsService $sheets,
        private InfluencerScoringService $scoring,
        private ProjectResolverService $projects,
        private CreatorLocationInferenceService $locationInference,
        private AvatarCacheService $avatarCache,
    ) {
    }

    public function mergeFromEnrichedSheet(string $sheetId, string $sourceSheet): array
    {
        $this->assertSourceSheet($sourceSheet);
        $sourceRows = $this->sheets->getRows($sheetId, $sourceSheet);

        return $this->mergeSourceRows($sheetId, $sourceSheet, $sourceRows);
    }

    public function mergeSelectedFromEnrichedSheet(string $sheetId, string $sourceSheet, array $rowNumbers): array
    {
        $this->assertSourceSheet($sourceSheet);
        $wanted = array_values(array_unique(array_filter(array_map('intval', $rowNumbers), fn ($row) => $row > 1)));
        $wantedLookup = array_fill_keys($wanted, true);

        $sourceRows = array_values(array_filter(
            $this->sheets->getRows($sheetId, $sourceSheet),
            fn (array $row) => isset($wantedLookup[(int) ($row['_row_number'] ?? 0)])
        ));

        $result = $this->mergeSourceRows($sheetId, $sourceSheet, $sourceRows);
        $result['selected'] = count($wanted);
        $result['matchedSelected'] = count($sourceRows);
        $result['missingSelected'] = max(0, count($wanted) - count($sourceRows));

        return $result;
    }

    private function mergeSourceRows(string $sheetId, string $sourceSheet, array $sourceRows): array
    {
        if ($this->shouldUseDatabaseFirstMerge()) {
            return $this->mergeSourceRowsDatabaseFirst($sheetId, $sourceSheet, $sourceRows);
        }

        return $this->mergeSourceRowsLegacy($sheetId, $sourceSheet, $sourceRows);
    }

    private function mergeSourceRowsLegacy(string $sheetId, string $sourceSheet, array $sourceRows): array
    {
        $crmHeaders = $this->sheets->getHeaders($sheetId, 'Creators_CRM');
        $crmRows = $this->sheets->getRows($sheetId, 'Creators_CRM');

        $crmIndex = [];
        $maxRowNumber = 1;

        foreach ($crmRows as $row) {
            $crmIndex[$this->crmKey($row['Platform'] ?? '', $row['Handle'] ?? '')] = $row;
            $maxRowNumber = max($maxRowNumber, (int) ($row['_row_number'] ?? 1));
        }

        $newRecords = [];
        $updates = [];
        $updated = 0;
        $created = 0;
        $skipped = 0;
        $unmatched = [];
        $affectedRowNumbers = [];
        $createdRowNumbers = [];
        $updatedRowNumbers = [];
        $nextRowNumber = $maxRowNumber + 1;

        foreach ($sourceRows as $sourceRow) {
            $creator = $this->sourceRowToCreatorRecord($sourceSheet, $sourceRow);
            $key = $this->crmKey($creator['Platform'], $creator['Handle']);

            if ($creator['Handle'] === '') {
                $skipped++;
                $unmatched[] = [
                    'row_number' => $sourceRow['_row_number'] ?? '',
                    'handle' => '',
                    'dm_link' => $creator['DM_Link'],
                    'status' => 'SKIPPED_NO_HANDLE',
                    'note' => 'Missing handle in enriched source row',
                ];
                continue;
            }

            if (isset($crmIndex[$key])) {
                $existing = $crmIndex[$key];
                $rowNumber = (int) ($existing['_row_number'] ?? 0);
                $merged = $this->mergeCreatorRecords($existing, $creator, $rowNumber, $sourceRow);

                $updates[] = [
                    'rowNumber' => $rowNumber,
                    'record' => $merged,
                ];

                $crmIndex[$key] = array_merge($existing, $merged);
                $updated++;
                $affectedRowNumbers[] = $rowNumber;
                $updatedRowNumbers[] = $rowNumber;
                continue;
            }

            $rowNumber = $nextRowNumber;
            $record = $this->applyCreatorDerivedFields($creator, $rowNumber, $sourceRow);
            $newRecords[] = $record;
            $crmIndex[$key] = array_merge($record, ['_row_number' => $rowNumber]);
            $created++;
            $affectedRowNumbers[] = $rowNumber;
            $createdRowNumbers[] = $rowNumber;
            $nextRowNumber++;
        }

        if (count($updates) > 0) {
            $this->sheets->batchUpdateAssocRows($sheetId, 'Creators_CRM', $updates, $crmHeaders);
        }

        if (count($newRecords) > 0) {
            $this->sheets->appendAssocRows($sheetId, 'Creators_CRM', $newRecords, $crmHeaders);
        }

        if (count($unmatched) > 0) {
            $this->sheets->appendAssocRows($sheetId, 'Merge_Unmatched', $unmatched);
        }

        $affectedRowNumbers = array_values(array_unique(array_filter(array_map('intval', $affectedRowNumbers), fn (int $rowNumber) => $rowNumber > 1)));
        sort($affectedRowNumbers);
        $createdRowNumbers = array_values(array_unique(array_filter(array_map('intval', $createdRowNumbers), fn (int $rowNumber) => $rowNumber > 1)));
        sort($createdRowNumbers);
        $updatedRowNumbers = array_values(array_unique(array_filter(array_map('intval', $updatedRowNumbers), fn (int $rowNumber) => $rowNumber > 1)));
        sort($updatedRowNumbers);

        return [
            'sourceSheet' => 'database',
            'processed' => count($sourceRows),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'affectedRowNumbers' => $affectedRowNumbers,
            'createdRowNumbers' => $createdRowNumbers,
            'updatedRowNumbers' => $updatedRowNumbers,
        ];
    }

    private function mergeSourceRowsDatabaseFirst(string $sheetId, string $sourceSheet, array $sourceRows): array
    {
        $project = $this->projects->resolveByWorkbookId($sheetId);

        $result = DB::transaction(function () use ($project, $sourceSheet, $sourceRows) {
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $affectedProfileIds = [];
            $affectedProfiles = [];
            $avatarUrls = [];

            foreach ($sourceRows as $sourceRow) {
                $creatorRecord = $this->sourceRowToCreatorRecord($sourceSheet, $sourceRow);
                $handle = $this->normalizeHandle((string) ($creatorRecord['Handle'] ?? ''));
                $platform = strtolower(trim((string) ($creatorRecord['Platform'] ?? '')));

                if ($platform === '' || $handle === '') {
                    $skipped++;
                    continue;
                }

                $profileUrl = trim((string) ($creatorRecord['DM_Link'] ?? ''));
                $profile = CreatorProfile::query()
                    ->where('project_id', $project->id)
                    ->where('platform', $platform)
                    ->where(function ($query) use ($handle, $profileUrl) {
                        $query->where('handle', $handle);
                        if ($profileUrl !== '') {
                            $query->orWhere('profile_url', $profileUrl)
                                ->orWhere('dm_link', $profileUrl);
                        }
                    })
                    ->first();

                $isNewProfile = !$profile;
                $creator = $this->resolveDatabaseCreator($project, $creatorRecord, $sourceRow, $profile);

                if (!$profile) {
                    $profile = new CreatorProfile();
                    $profile->project_id = $project->id;
                    $profile->platform = $platform;
                    $profile->handle = $handle;
                }

                $this->fillDatabaseProfile($profile, $creator, $creatorRecord, $sourceRow);
                if ((string) ($profile->profile_pic_url ?? '') !== '') {
                    $avatarUrls[] = (string) $profile->profile_pic_url;
                }
                $profile->save();

                $affectedProfileIds[] = $profile->id;
                $affectedProfiles[] = [
                    'profile_id' => $profile->id,
                    'creator_id' => $creator->id,
                    'creator_record' => $creatorRecord,
                    'source_row' => $sourceRow,
                ];

                if ($isNewProfile) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            return [
                'sourceSheet' => 'database',
                'processed' => count($sourceRows),
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'affectedProfileIds' => array_values(array_unique($affectedProfileIds)),
                'affectedProfiles' => $affectedProfiles,
                'avatarUrls' => array_values(array_unique($avatarUrls)),
            ];
        });

        $sheetSync = $this->syncMergedProfilesToCrmSheet($sheetId, $project, $result['affectedProfiles']);
        $this->avatarCache->warmManyAfterResponse($result['avatarUrls'] ?? [], 25);

        return [
            'sourceSheet' => 'database',
            'processed' => $result['processed'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'affectedProfileIds' => $result['affectedProfileIds'],
            'affectedRowNumbers' => $sheetSync['affectedRowNumbers'],
            'createdRowNumbers' => $sheetSync['createdRowNumbers'],
            'updatedRowNumbers' => $sheetSync['updatedRowNumbers'],
            'dbFirst' => true,
            'sheetSync' => $sheetSync,
        ];
    }

    private function shouldUseDatabaseFirstMerge(): bool
    {
        return config('outreach.operational_db.mode', 'dual') !== 'off';
    }

    private function resolveDatabaseCreator(Project $project, array $creatorRecord, array $sourceRow, ?CreatorProfile $existingProfile): Creator
    {
        $identityKey = $this->databaseCreatorIdentityKey($creatorRecord, $sourceRow);
        $creator = null;

        if ($existingProfile?->creator_id) {
            $creator = Creator::query()->find($existingProfile->creator_id);
        }

        if (!$creator) {
            $creator = Creator::query()
                ->where('project_id', $project->id)
                ->where('external_identity_key', $identityKey)
                ->first();
        }

        $email = trim((string) ($creatorRecord['Contact_Email'] ?? ''));
        if (!$creator && $email !== '') {
            $creator = Creator::query()
                ->where('project_id', $project->id)
                ->where('primary_email', $email)
                ->first();
        }

        if (!$creator) {
            $creator = new Creator();
            $creator->project_id = $project->id;
            $creator->external_identity_key = $identityKey;
        }

        $incomingNotes = trim((string) ($creatorRecord['Notes'] ?? ''));
        $creator->display_name = trim((string) ($creatorRecord['Name'] ?? '')) !== ''
            ? trim((string) ($creatorRecord['Name'] ?? ''))
            : ($creator->display_name ?: ltrim((string) ($creatorRecord['Handle'] ?? ''), '@'));
        $creator->primary_email = $email !== '' ? $email : $creator->primary_email;
        $creator->country = trim((string) ($creatorRecord['Country'] ?? '')) ?: $creator->country;
        $creator->city = trim((string) ($creatorRecord['City'] ?? '')) ?: $creator->city;
        $creator->primary_language = trim((string) ($creatorRecord['Primary_Language'] ?? '')) ?: $creator->primary_language;
        $creator->niche_category = trim((string) ($creatorRecord['Niche_Category'] ?? '')) ?: $creator->niche_category;
        $creator->notes = $this->appendNote((string) ($creator->notes ?? ''), $incomingNotes);
        $this->locationInference->applyToCreator($creator, array_merge($sourceRow, $creatorRecord), (string) ($creatorRecord['Platform'] ?? ''));
        $metadata = is_array($creator->metadata) ? $creator->metadata : [];
        $metadata['last_merged_from'] = ($sourceRow['_row_number'] ?? null) ? 'enriched:' . (int) $sourceRow['_row_number'] : 'enriched';
        $metadata['last_merged_at'] = now()->toDateTimeString();
        $creator->metadata = $metadata;
        $creator->save();

        return $creator;
    }

    private function fillDatabaseProfile(CreatorProfile $profile, Creator $creator, array $creatorRecord, array $sourceRow): void
    {
        $existingStatus = strtoupper(trim((string) ($profile->status ?? '')));
        $incomingStatus = trim((string) ($creatorRecord['Status'] ?? 'NEW')) ?: 'NEW';
        $incomingProfilePicUrl = trim((string) (
    $sourceRow['profilePicUrl']
    ?? $sourceRow['avatarUrl']
    ?? $sourceRow['profile_pic_url']
    ?? ''
));

$profile->creator_id = $creator->id;
$profile->username = ltrim($this->normalizeHandle((string) ($creatorRecord['Handle'] ?? '')), '@');
$profile->profile_url = trim((string) ($creatorRecord['DM_Link'] ?? '')) ?: ($profile->profile_url ?: null);
$profile->dm_link = trim((string) ($creatorRecord['DM_Link'] ?? '')) ?: ($profile->dm_link ?: null);
$profile->profile_pic_url = $incomingProfilePicUrl !== '' ? $incomingProfilePicUrl : ($profile->profile_pic_url ?: null);
$profile->status = $existingStatus !== '' && !in_array($existingStatus, ['NEW', 'DISCOVERED', 'ENRICHED'], true)
    ? $profile->status
    : $incomingStatus;
        $profile->lifecycle_state = strtolower(str_replace(' ', '_', trim((string) ($profile->status ?: $incomingStatus))));
        $profile->followers_count = is_numeric((string) ($creatorRecord['Followers'] ?? '')) ? (int) round((float) $creatorRecord['Followers']) : $profile->followers_count;
        $profile->engagement_rate_pct = is_numeric((string) ($creatorRecord['Engagement_Rate_%'] ?? '')) ? (float) $creatorRecord['Engagement_Rate_%'] : $profile->engagement_rate_pct;
        $profile->preferred_channel = trim((string) ($creatorRecord['Preferred_Channel'] ?? '')) ?: ($profile->preferred_channel ?: null);
        $profile->value_score = (int) round($this->scoring->score($creatorRecord, $sourceRow));
        $profile->value_bar = $this->scoring->bar((float) $profile->value_score);
        $profile->duplicate_flag = $profile->duplicate_flag ?: null;
        $profile->source_provider = 'database';
        $profile->source_reference = $profile->source_reference ?: ('creator_profile:' . ($profile->id ?: 'pending'));
        $metadata = is_array($profile->source_metadata) ? $profile->source_metadata : [];
        $metadata['merged_from_source_sheet'] = $sourceRow['_row_number'] ?? null;
        $metadata['merged_from_platform'] = strtolower(trim((string) ($creatorRecord['Platform'] ?? '')));
        $locationInference = $this->locationInference->infer(array_merge($sourceRow, $creatorRecord), (string) ($creatorRecord['Platform'] ?? ''));
        if ((float) ($locationInference['confidence'] ?? 0) > 0) {
            $metadata['creator_location'] = $locationInference;
        }
        $metadata['commission_model'] = trim((string) ($creatorRecord['Commission_Model'] ?? '')) ?: ($metadata['commission_model'] ?? null);
        $metadata['reaction_video_link'] = trim((string) ($creatorRecord['Reaction_Video_Link'] ?? '')) ?: ($metadata['reaction_video_link'] ?? null);
        $profile->source_metadata = $metadata;
        $profile->last_synced_at = now();
    }

    private function syncMergedProfilesToCrmSheet(string $sheetId, Project $project, array $affectedProfiles): array
    {
        if ($affectedProfiles === []) {
            return [
                'updated' => 0,
                'appended' => 0,
                'affectedRowNumbers' => [],
                'createdRowNumbers' => [],
                'updatedRowNumbers' => [],
                'warnings' => [],
            ];
        }

        try {
            $crmHeaders = $this->sheets->getHeaders($sheetId, 'Creators_CRM');
            $crmRows = $this->sheets->getRows($sheetId, 'Creators_CRM');
            $crmByKey = [];
            $maxRowNumber = 1;
            foreach ($crmRows as $row) {
                $crmByKey[$this->crmKey((string) ($row['Platform'] ?? ''), (string) ($row['Handle'] ?? ''))] = $row;
                $maxRowNumber = max($maxRowNumber, (int) ($row['_row_number'] ?? 1));
            }

            $updates = [];
            $newRecords = [];
            $newProfileIds = [];
            $affectedRowNumbers = [];
            $createdRowNumbers = [];
            $updatedRowNumbers = [];
            $nextRowNumber = $maxRowNumber + 1;

            foreach ($affectedProfiles as $item) {
                $profile = CreatorProfile::query()->with('creator')->find($item['profile_id']);
                if (!$profile || !$profile->creator) {
                    continue;
                }

                $existingRowNumber = $this->extractSheetRowNumber($profile);
                if ($existingRowNumber <= 1) {
                    $existing = $crmByKey[$this->crmKey($this->displayPlatform((string) $profile->platform), (string) $profile->handle)] ?? null;
                    $existingRowNumber = (int) ($existing['_row_number'] ?? 0);
                }

                $rowNumber = $existingRowNumber > 1 ? $existingRowNumber : $nextRowNumber;
                $record = $this->databaseProfileToCrmRecord($profile, $item['creator_record'], $item['source_row'], $rowNumber);

                if ($existingRowNumber > 1) {
                    $updates[] = ['rowNumber' => $rowNumber, 'record' => $record];
                    $updatedRowNumbers[] = $rowNumber;
                } else {
                    $newRecords[] = $record;
                    $newProfileIds[] = $profile->id;
                    $createdRowNumbers[] = $rowNumber;
                    $nextRowNumber++;
                }

                $affectedRowNumbers[] = $rowNumber;
            }

            if ($updates !== []) {
                $this->sheets->batchUpdateAssocRows($sheetId, 'Creators_CRM', $updates, $crmHeaders);
            }
            if ($newRecords !== []) {
                $this->sheets->appendAssocRows($sheetId, 'Creators_CRM', $newRecords, $crmHeaders);
            }

            $assignedRows = array_merge($updatedRowNumbers, $createdRowNumbers);
            $assignedProfileIds = array_merge(array_map(fn ($u) => null, $updatedRowNumbers), $newProfileIds);
            if ($newProfileIds !== []) {
                foreach (array_values($newProfileIds) as $idx => $profileId) {
                    $rowNumber = $createdRowNumbers[$idx] ?? null;
                    if (!$rowNumber) {
                        continue;
                    }
                    $profile = CreatorProfile::query()->find($profileId);
                    if (!$profile) {
                        continue;
                    }
                    $metadata = is_array($profile->source_metadata) ? $profile->source_metadata : [];
                    $metadata['sheet_row_number'] = $rowNumber;
                    $metadata['last_sheet_sync_at'] = now()->toDateTimeString();
                    $profile->source_provider = 'database';
                    $profile->source_reference = 'Creators_CRM:' . $rowNumber;
                    $profile->source_metadata = $metadata;
                    $profile->last_synced_at = now();
                    $profile->save();
                }
            }

            return [
                'updated' => count($updates),
                'appended' => count($newRecords),
                'affectedRowNumbers' => array_values(array_unique(array_filter(array_map('intval', $affectedRowNumbers), fn (int $row) => $row > 1))),
                'createdRowNumbers' => array_values(array_unique(array_filter(array_map('intval', $createdRowNumbers), fn (int $row) => $row > 1))),
                'updatedRowNumbers' => array_values(array_unique(array_filter(array_map('intval', $updatedRowNumbers), fn (int $row) => $row > 1))),
                'warnings' => [],
            ];
        } catch (\Throwable $e) {
            report($e);
            return [
                'updated' => 0,
                'appended' => 0,
                'affectedRowNumbers' => [],
                'createdRowNumbers' => [],
                'updatedRowNumbers' => [],
                'warnings' => [$e->getMessage()],
            ];
        }
    }

    private function extractSheetRowNumber(CreatorProfile $profile): int
    {
        if (preg_match('/Creators_CRM:(\d+)/', (string) ($profile->source_reference ?? ''), $m)) {
            return (int) $m[1];
        }

        return (int) (($profile->source_metadata['sheet_row_number'] ?? 0));
    }

    private function databaseProfileToCrmRecord(CreatorProfile $profile, array $creatorRecord, ?array $sourceRow, int $rowNumber): array
    {
        $creator = $profile->creator;
        $record = [
            'Platform' => $this->displayPlatform((string) $profile->platform),
            'Handle' => (string) $profile->handle,
            'Name' => (string) ($creator?->display_name ?: ($creatorRecord['Name'] ?? '')),
            'Followers' => $profile->followers_count ?? '',
            'Engagement_Rate_%' => $profile->engagement_rate_pct ?? '',
            'Country' => (string) ($creator?->country ?: ($creatorRecord['Country'] ?? '')),
            'City' => (string) ($creator?->city ?: ($creatorRecord['City'] ?? '')),
            'Primary_Language' => (string) ($creator?->primary_language ?: ($creatorRecord['Primary_Language'] ?? '')),
            'Niche_Category' => (string) ($creator?->niche_category ?: ($creatorRecord['Niche_Category'] ?? '')),
            'Angle_Assigned' => (string) (($profile->source_metadata['angle_assigned'] ?? '') ?: ($creatorRecord['Angle_Assigned'] ?? '')),
            'Contact_Email' => (string) ($creator?->primary_email ?: ($creatorRecord['Contact_Email'] ?? '')),
            'DM_Link' => (string) ($profile->dm_link ?: $profile->profile_url ?: ($creatorRecord['DM_Link'] ?? '')),
            'Status' => (string) ($profile->status ?: ($creatorRecord['Status'] ?? 'NEW')),
            'DM_Sent_Date' => optional($profile->dm_sent_at)?->toDateTimeString() ?? '',
            'Response_Date' => optional($profile->responded_at)?->toDateTimeString() ?? '',
            'Accepted_(Y/N)' => $profile->accepted_flag ? 'Y' : 'N',
            'Commission_Model' => (string) (($profile->source_metadata['commission_model'] ?? '') ?: ($creatorRecord['Commission_Model'] ?? '')),
            'Unique_Tracking_Code' => (string) (($profile->source_metadata['unique_tracking_code'] ?? '') ?: ($creatorRecord['Unique_Tracking_Code'] ?? '')),
            'Reaction_Video_Link' => (string) (($profile->source_metadata['reaction_video_link'] ?? '') ?: ($creatorRecord['Reaction_Video_Link'] ?? '')),
            'PDF_Sales' => (string) (($profile->source_metadata['pdf_sales'] ?? '') ?: ($creatorRecord['PDF_Sales'] ?? 0)),
            'Product_Sales' => (string) (($profile->source_metadata['product_sales'] ?? '') ?: ($creatorRecord['Product_Sales'] ?? 0)),
            'Total_Revenue' => (string) (($profile->source_metadata['total_revenue'] ?? '') ?: ($creatorRecord['Total_Revenue'] ?? 0)),
            'Follow_Up_Needed_(Y/N)' => $profile->follow_up_needed ? 'Y' : 'N',
            'Notes' => (string) ($creator?->notes ?: ($creatorRecord['Notes'] ?? '')),
        ];

        return $this->applyCreatorDerivedFields($record, $rowNumber, $sourceRow);
    }

    private function displayPlatform(string $platform): string
    {
        return strtolower($platform) === 'instagram' ? 'Instagram' : (strtolower($platform) === 'tiktok' ? 'TikTok' : ucfirst($platform));
    }

    private function databaseCreatorIdentityKey(array $creatorRecord, array $sourceRow): string
    {
        $email = strtolower(trim((string) ($creatorRecord['Contact_Email'] ?? '')));
        if ($email !== '') {
            return 'email:' . $email;
        }

        $name = strtolower(trim((string) ($creatorRecord['Name'] ?? '')));
        if ($name !== '') {
            return 'name:' . $name;
        }

        $externalId = strtolower(trim((string) ($sourceRow['id'] ?? '')));
        if ($externalId !== '') {
            return 'source:' . $externalId;
        }

        return 'profile:' . strtolower(trim((string) ($creatorRecord['Platform'] ?? ''))) . '|' . strtolower($this->normalizeHandle((string) ($creatorRecord['Handle'] ?? '')));
    }

    private function sourceRowToCreatorRecord(string $sourceSheet, array $sourceRow): array
    {
        $platform = Str::startsWith($sourceSheet, 'Instagram') ? 'Instagram' : 'TikTok';
        $handle = $this->normalizeHandle((string) ($sourceRow['handle'] ?? $sourceRow['username'] ?? ''));
        $profileUrl = (string) ($sourceRow['profile_url'] ?? $sourceRow['input_url'] ?? '');
        $email = (string) ($sourceRow['email_from_bio'] ?? '');
        $followers = $sourceRow['followersCount'] ?? '';
        $engagement = $platform === 'Instagram'
            ? ($sourceRow['recent_est_engagement_rate_pct'] ?? '')
            : $this->estimateTikTokEngagement($sourceRow);
        $language = $platform === 'Instagram'
            ? ''
            : (string) ($sourceRow['language'] ?? '');
        $locationInference = $this->locationInference->infer(array_merge($sourceRow, [
            'fullName' => $sourceRow['fullName'] ?? $sourceRow['nickname'] ?? $sourceRow['username'] ?? '',
            'bio' => $sourceRow['biography'] ?? $sourceRow['bio'] ?? $sourceRow['signature'] ?? '',
        ]), strtolower($platform));
        $nowIso = now()->toDateTimeString();
        $notes = $platform === 'Instagram'
            ? sprintf(
                'source=ig_enriched; added_to_crm_at=%s; followers=%s; recent_er_pct=%s; posts_used=%s; posts_count=%s; verified=%s; private=%s; ext=%s',
                $nowIso,
                $followers,
                $sourceRow['recent_est_engagement_rate_pct'] ?? '',
                $sourceRow['recent_posts_used_for_engagement'] ?? '',
                $sourceRow['postsCount'] ?? $sourceRow['latestPosts_count'] ?? '',
                $sourceRow['verified'] ?? '',
                $sourceRow['private'] ?? '',
                $sourceRow['externalUrl'] ?? ''
            )
            : sprintf(
                'source=tiktok_enriched; added_to_crm_at=%s; followers=%s; avg_views=%s; avg_likes=%s; posts_used=%s; posts_count=%s; verified=%s; private=%s; ext=%s',
                $nowIso,
                $followers,
                $sourceRow['recent_avg_views'] ?? '',
                $sourceRow['recent_avg_likes'] ?? '',
                $sourceRow['recent_posts_used'] ?? '',
                $sourceRow['videoCount'] ?? $sourceRow['latestPosts_count'] ?? '',
                $sourceRow['verified'] ?? '',
                $sourceRow['private'] ?? '',
                $sourceRow['externalUrl'] ?? ''
            );

        return [
            'Platform' => $platform,
            'Handle' => $handle,
            'Name' => (string) ($sourceRow['fullName'] ?? $sourceRow['nickname'] ?? $sourceRow['username'] ?? ''),
            'Followers' => $followers,
            'Engagement_Rate_%' => $engagement,
            'Country' => (string) (($locationInference['country'] ?? '') ?: ($sourceRow['region'] ?? '')),
            'City' => (string) (($locationInference['city'] ?? '') ?: ''),
            'Primary_Language' => $language,
            'Niche_Category' => (string) ($sourceRow['niche_category'] ?? ''),
            'Angle_Assigned' => '',
            'Contact_Email' => $email,
            'DM_Link' => $profileUrl,
            'Status' => 'NEW',
            'DM_Sent_Date' => '',
            'Response_Date' => '',
            'Accepted_(Y/N)' => 'N',
            'Commission_Model' => '',
            'Unique_Tracking_Code' => '',
            'Reaction_Video_Link' => '',
            'PDF_Sales' => 0,
            'Product_Sales' => 0,
            'Total_Revenue' => 0,
            'Follow_Up_Needed_(Y/N)' => 'N',
            'Notes' => trim($notes),
            'Preferred_Channel' => '',
            'Last_Content_Date' => '',
            'Value_Score' => '',
            'Value_Bar' => '',
            'Duplicate_Flag' => '',
        ];
    }

    private function mergeCreatorRecords(array $existing, array $incoming, int $rowNumber, ?array $sourceRow = null): array
    {
        $merged = $existing;

        foreach ($incoming as $field => $value) {
            if (in_array($field, ['Preferred_Channel', 'Last_Content_Date', 'Value_Score', 'Value_Bar', 'Duplicate_Flag'], true)) {
                continue;
            }

            $existingValue = (string) ($existing[$field] ?? '');
            $incomingValue = (string) $value;

            if ($incomingValue !== '' && ($existingValue === '' || in_array($field, ['Followers', 'Engagement_Rate_%', 'Contact_Email', 'DM_Link', 'Name', 'Primary_Language'], true))) {
                $merged[$field] = $incomingValue;
            }
        }

        $merged['Notes'] = $this->appendNote((string) ($existing['Notes'] ?? ''), (string) ($incoming['Notes'] ?? ''));

        return $this->applyCreatorDerivedFields($merged, $rowNumber, $sourceRow);
    }

    private function applyCreatorDerivedFields(array $record, int $rowNumber, ?array $sourceRow = null): array
    {
        $record['Preferred_Channel'] = trim((string) ($record['Contact_Email'] ?? '')) !== '' ? 'Email' : 'DM';
        $record['Last_Content_Date'] = $this->formulaLastContentDate($rowNumber);

        $score = $this->scoring->score($record, $sourceRow);
        $record['Value_Score'] = (string) $score;
        $record['Value_Bar'] = $this->scoring->bar($score);
        $record['Duplicate_Flag'] = '=IF(COUNTIFS($A:$A,$A' . $rowNumber . ',$B:$B,$B' . $rowNumber . ')>1,"DUP","")';

        return $record;
    }

    private function formulaLastContentDate(int $rowNumber): string
    {
        return '=IF($A' . $rowNumber . '="TikTok",IFERROR(DATEVALUE(LEFT(VLOOKUP($B' . $rowNumber . ',\'TikTok_Creators\'!$B:$N,13,FALSE),10)),""),IF($A' . $rowNumber . '="Instagram",IFERROR(DATEVALUE(LEFT(VLOOKUP($B' . $rowNumber . ',\'Instagram_Creators\'!$B:$F,5,FALSE),10)),""),""))';
    }

    private function estimateTikTokEngagement(array $sourceRow): string
    {
        $followers = (float) ($sourceRow['followersCount'] ?? 0);
        $avgLikes = (float) ($sourceRow['recent_avg_likes'] ?? 0);
        $avgComments = (float) ($sourceRow['recent_avg_comments'] ?? 0);

        if ($followers <= 0 || ($avgLikes <= 0 && $avgComments <= 0)) {
            return '';
        }

        return (string) round((($avgLikes + $avgComments) / $followers) * 100, 2);
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

        if (str_contains($existing, $incoming)) {
            return $existing;
        }

        return $existing . ' | ' . $incoming;
    }

    private function crmKey(string $platform, string $handle): string
    {
        return strtolower(trim($platform)) . '|' . strtolower(trim($this->normalizeHandle($handle)));
    }

    private function normalizeHandle(string $handle): string
    {
        $handle = trim($handle);

        if ($handle === '') {
            return '';
        }

        return str_starts_with($handle, '@') ? $handle : '@' . $handle;
    }

    private function assertSourceSheet(string $sourceSheet): void
    {
        if (!in_array($sourceSheet, self::SOURCE_SHEETS, true)) {
            throw new RuntimeException('Invalid source sheet for merge');
        }
    }
}
