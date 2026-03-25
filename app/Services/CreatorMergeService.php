<?php

namespace App\Services;

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
            'sourceSheet' => $sourceSheet,
            'processed' => count($sourceRows),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'affectedRowNumbers' => $affectedRowNumbers,
            'createdRowNumbers' => $createdRowNumbers,
            'updatedRowNumbers' => $updatedRowNumbers,
        ];
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
            'Country' => (string) ($sourceRow['region'] ?? ''),
            'City' => '',
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
