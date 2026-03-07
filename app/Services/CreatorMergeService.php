<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class CreatorMergeService
{
    public const SOURCE_SHEETS = [
        'Instagram_Profile_Enriched',
        'TikTok_Profile_Enriched',
    ];

    public function __construct(private GoogleSheetsService $sheets)
    {
    }

public function mergeFromEnrichedSheet(string $sheetId, string $sourceSheet): array
{
    if (!in_array($sourceSheet, self::SOURCE_SHEETS, true)) {
        throw new RuntimeException('Invalid source sheet for merge');
    }

    $sourceRows = $this->sheets->getRows($sheetId, $sourceSheet);
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

            $merged = $this->mergeCreatorRecords($existing, $creator, $rowNumber);

            $updates[] = [
                'rowNumber' => $rowNumber,
                'record' => $merged,
            ];

            $crmIndex[$key] = array_merge($existing, $merged);
            $updated++;
            continue;
        }

        $record = $this->applyCreatorFormulas($creator, $nextRowNumber);
        $newRecords[] = $record;
        $crmIndex[$key] = array_merge($record, ['_row_number' => $nextRowNumber]);
        $created++;
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

    return [
        'sourceSheet' => $sourceSheet,
        'processed' => count($sourceRows),
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
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
            : '';
        $language = $platform === 'Instagram'
            ? ''
            : (string) ($sourceRow['language'] ?? '');
        $notes = $platform === 'Instagram'
            ? sprintf(
                'IG enriched import; followers=%s; recentER%%~=%s; postsUsed=%s; biz=%s; verified=%s; private=%s; ext=%s',
                $followers,
                $sourceRow['recent_est_engagement_rate_pct'] ?? '',
                $sourceRow['recent_posts_used_for_engagement'] ?? '',
                $sourceRow['isBusinessAccount'] ?? '',
                $sourceRow['verified'] ?? '',
                $sourceRow['private'] ?? '',
                $sourceRow['externalUrl'] ?? ''
            )
            : sprintf(
                'TikTok enriched import; followers=%s; avgViews=%s; avgLikes=%s; postsUsed=%s; verified=%s; private=%s; ext=%s',
                $followers,
                $sourceRow['recent_avg_views'] ?? '',
                $sourceRow['recent_avg_likes'] ?? '',
                $sourceRow['recent_posts_used'] ?? '',
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
            'Niche_Category' => '',
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

    private function mergeCreatorRecords(array $existing, array $incoming, int $rowNumber): array
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

        return $this->applyCreatorFormulas($merged, $rowNumber);
    }

    private function applyCreatorFormulas(array $record, int $rowNumber): array
    {
        $record['Preferred_Channel'] = '=IF(LEN($K' . $rowNumber . ')>0,"Email","DM")';
        $record['Last_Content_Date'] = '=IF($A' . $rowNumber . '="TikTok",IFERROR(DATEVALUE(LEFT(VLOOKUP($B' . $rowNumber . ',\'TikTok_Creators\'!$B:$N,13,FALSE),10)),""),IF($A' . $rowNumber . '="Instagram",IFERROR(DATEVALUE(LEFT(VLOOKUP($B' . $rowNumber . ',\'Instagram_Creators\'!$B:$F,5,FALSE),10)),""),""))';
        $record['Value_Score'] = '=IF($A' . $rowNumber . '="Instagram",IFERROR(VLOOKUP($B' . $rowNumber . ',\'Instagram_Creators\'!$B:$H,7,FALSE),""),IF($A' . $rowNumber . '="TikTok",IFERROR(MIN(100,ROUND(LOG10(1+VLOOKUP($B' . $rowNumber . ',\'TikTok_Creators\'!$B:$K,10,FALSE))*25 + LOG10(1+VLOOKUP($B' . $rowNumber . ',\'TikTok_Creators\'!$B:$J,9,FALSE))*20,0)),""),""))';
        $record['Value_Bar'] = '=IF(LEN($AA' . $rowNumber . ')=0,"",REPT("█",ROUND($AA' . $rowNumber . '/10,0))&REPT("░",10-ROUND($AA' . $rowNumber . '/10,0)))';
        $record['Duplicate_Flag'] = '=IF(COUNTIFS($A:$A,$A' . $rowNumber . ',$B:$B,$B' . $rowNumber . ')>1,"DUP","")';

        return $record;
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
}
