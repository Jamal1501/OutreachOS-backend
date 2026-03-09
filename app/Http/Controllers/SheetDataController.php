<?php

namespace App\Http\Controllers;

use App\Services\GoogleSheetsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SheetDataController extends Controller
{
    public function __construct(
        private GoogleSheetsService $sheets,
    ) {
    }

    public function discoveryList(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $platform = strtolower(trim((string) ($validated['platform'] ?? '')));
        $search = trim((string) ($validated['search'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 50);
        $offset = (int) ($validated['offset'] ?? 0);

        $items = [];
        $includeInstagram = $platform === '' || $platform === 'all' || $platform === 'instagram';
        $includeTikTok = $platform === '' || $platform === 'all' || $platform === 'tiktok';

        if ($includeInstagram) {
            foreach ($this->sheets->getRows($sheetId, 'Instagram_Posts_Raw') as $row) {
                $row['id'] = 'instagram:' . (string) ($row['_row_number'] ?? '');
                $row['_sheet'] = 'Instagram_Posts_Raw';
                $row['_platform'] = 'instagram';
                $items[] = $row;
            }
        }

        if ($includeTikTok) {
            foreach ($this->sheets->getRows($sheetId, 'TikTok_Posts_Raw') as $row) {
                $row['id'] = 'tiktok:' . (string) ($row['_row_number'] ?? '');
                $row['_sheet'] = 'TikTok_Posts_Raw';
                $row['_platform'] = 'tiktok';
                $items[] = $row;
            }
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, function (array $row) use ($needle) {
                $haystacks = [
                    (string) ($row['ownerUsername'] ?? ''),
                    (string) ($row['ownerFullName'] ?? ''),
                    (string) ($row['caption'] ?? ''),
                    (string) ($row['Post_Niche'] ?? ''),
                    (string) ($row['Reason_Summary'] ?? ''),
                    (string) ($row['authorMeta.name'] ?? ''),
                    (string) ($row['text'] ?? ''),
                    (string) ($row['webVideoUrl'] ?? ''),
                    (string) ($row['url'] ?? ''),
                ];

                foreach ($haystacks as $value) {
                    if ($value !== '' && str_contains(mb_strtolower($value), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        usort($items, function (array $a, array $b) {
            $aTime = (string) ($a['timestamp'] ?? $a['createTimeISO'] ?? '');
            $bTime = (string) ($b['timestamp'] ?? $b['createTimeISO'] ?? '');

            return strcmp($bTime, $aTime);
        });

        $total = count($items);
        $items = array_slice($items, $offset, $limit);

        return response()->json([
            'message' => 'Discovery rows fetched',
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function discoveryExtractUrls(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'postIds' => ['required', 'array', 'min:1'],
            'postIds.*' => ['required'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);

        $result = $this->appendDiscoveryPostsToQueues($sheetId, $validated['postIds']);

        return response()->json([
            'message' => 'Profile URLs extracted to queue',
            'items' => $result['items'],
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);
    }

    public function discoveryPushToEnrichment(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'postIds' => ['required', 'array', 'min:1'],
            'postIds.*' => ['required'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);

        $result = $this->appendDiscoveryPostsToQueues($sheetId, $validated['postIds']);

        return response()->json([
            'message' => 'Selected profiles pushed to enrichment queue',
            'items' => $result['items'],
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);
    }

    public function crmList(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'platform' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $search = trim((string) ($validated['search'] ?? ''));
        $platform = trim((string) ($validated['platform'] ?? ''));
        $status = trim((string) ($validated['status'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 50);
        $offset = (int) ($validated['offset'] ?? 0);

        $items = array_map(function (array $row) {
            $row['id'] = (string) ($row['_row_number'] ?? '');
            return $row;
        }, $this->sheets->getRows($sheetId, 'Creators_CRM'));

        if ($platform !== '' && strtolower($platform) !== 'all') {
            $items = array_values(array_filter($items, fn (array $row) => strtolower((string) ($row['Platform'] ?? '')) === strtolower($platform)));
        }

        if ($status !== '' && strtolower($status) !== 'all') {
            $items = array_values(array_filter($items, fn (array $row) => strtolower((string) ($row['Status'] ?? '')) === strtolower($status)));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, function (array $row) use ($needle) {
                $haystacks = [
                    (string) ($row['Handle'] ?? ''),
                    (string) ($row['Name'] ?? ''),
                    (string) ($row['Niche_Category'] ?? ''),
                    (string) ($row['Contact_Email'] ?? ''),
                    (string) ($row['Country'] ?? ''),
                    (string) ($row['City'] ?? ''),
                    (string) ($row['Notes'] ?? ''),
                ];

                foreach ($haystacks as $value) {
                    if ($value !== '' && str_contains(mb_strtolower($value), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        usort($items, function (array $a, array $b) {
            return (float) ($b['Value_Score'] ?? 0) <=> (float) ($a['Value_Score'] ?? 0);
        });

        $total = count($items);
        $items = array_slice($items, $offset, $limit);

        return response()->json([
            'message' => 'CRM rows fetched',
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function messagesList(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['nullable', 'string'],
            'stage' => ['nullable', 'string'],
            'niche' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $platform = trim((string) ($validated['platform'] ?? ''));
        $stage = trim((string) ($validated['stage'] ?? ''));
        $niche = trim((string) ($validated['niche'] ?? ''));

        $items = array_map(function (array $row) {
            $row['id'] = (string) ($row['_row_number'] ?? '');
            return $row;
        }, $this->sheets->getRows($sheetId, 'Message_Library'));

        if ($platform !== '' && strtolower($platform) !== 'all') {
            $items = array_values(array_filter($items, function (array $row) use ($platform) {
                $bestFor = strtolower((string) ($row['Best_For_Platform'] ?? ''));
                return $bestFor === '' || str_contains($bestFor, strtolower($platform)) || str_contains($bestFor, 'all');
            }));
        }

        if ($stage !== '') {
            $needle = strtolower($stage);
            $items = array_values(array_filter($items, function (array $row) use ($needle) {
                $angle = strtolower((string) ($row['Angle_Name'] ?? ''));
                $trigger = strtolower((string) ($row['Psychological_Trigger'] ?? ''));
                return str_contains($angle, $needle) || str_contains($trigger, $needle);
            }));
        }

        if ($niche !== '') {
            $needle = strtolower($niche);
            $items = array_values(array_filter($items, function (array $row) use ($needle) {
                $angle = strtolower((string) ($row['Angle_Name'] ?? ''));
                $copy = strtolower((string) ($row['DM_Template'] ?? ''));
                $trigger = strtolower((string) ($row['Psychological_Trigger'] ?? ''));
                return str_contains($angle, $needle) || str_contains($copy, $needle) || str_contains($trigger, $needle);
            }));
        }

        return response()->json([
            'message' => 'Message templates fetched',
            'items' => $items,
        ]);
    }

    public function messagesCreate(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'template' => ['required', 'array'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $template = $this->normalizeMessageTemplate($validated['template']);

        $this->sheets->appendAssocRows($sheetId, 'Message_Library', [$template]);
        $items = $this->sheets->getRows($sheetId, 'Message_Library');
        $created = end($items) ?: [];
        if ($created !== []) {
            $created['id'] = (string) ($created['_row_number'] ?? '');
        }

        return response()->json([
            'message' => 'Message template created',
            'items' => $created === [] ? [] : [$created],
        ]);
    }

    public function messagesUpdate(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'template' => ['required', 'array'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $rowNumber = $this->resolveMessageRowNumber($sheetId, $id);
        if ($rowNumber === null) {
            return response()->json([
                'message' => 'Message template not found',
                'items' => [],
            ], 404);
        }

        $existing = $this->findRowByRowNumber($sheetId, 'Message_Library', $rowNumber) ?? [];
        $record = array_merge($existing, $this->normalizeMessageTemplate($validated['template'], false));
        $this->sheets->updateAssocRow($sheetId, 'Message_Library', $rowNumber, $record);

        $updated = $this->findRowByRowNumber($sheetId, 'Message_Library', $rowNumber) ?? [];
        if ($updated !== []) {
            $updated['id'] = (string) ($updated['_row_number'] ?? '');
        }

        return response()->json([
            'message' => 'Message template updated',
            'items' => $updated === [] ? [] : [$updated],
        ]);
    }

    public function messagesDelete(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $rowNumber = $this->resolveMessageRowNumber($sheetId, $id);
        if ($rowNumber === null) {
            return response()->json([
                'message' => 'Message template not found',
                'items' => [],
            ], 404);
        }

        $deleted = $this->findRowByRowNumber($sheetId, 'Message_Library', $rowNumber) ?? [];
        $this->sheets->clearAssocRow($sheetId, 'Message_Library', $rowNumber);
        if ($deleted !== []) {
            $deleted['id'] = (string) ($deleted['_row_number'] ?? '');
        }

        return response()->json([
            'message' => 'Message template cleared',
            'items' => $deleted === [] ? [] : [$deleted],
        ]);
    }

    public function enrichmentQueue(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $platform = strtolower(trim((string) ($validated['platform'] ?? '')));

        $sheetName = match ($platform) {
            'instagram' => 'IG_Profile_URL_Queue',
            'tiktok' => 'TikTok_Profile_URL_Queue',
            default => 'Profile_URL_Queue_All',
        };

        $items = array_map(function (array $row) use ($sheetName) {
            $row['id'] = (string) ($row['_row_number'] ?? '');
            $row['_sheet'] = $sheetName;
            return $row;
        }, $this->sheets->getRows($sheetId, $sheetName));

        $items = array_values(array_filter($items, function (array $row) {
            return trim((string) ($row['handle'] ?? $row['url'] ?? '')) !== '';
        }));

        return response()->json([
            'message' => 'Enrichment queue fetched',
            'items' => $items,
        ]);
    }

    public function dashboardMetrics(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $crm = $this->sheets->getRows($sheetId, 'Creators_CRM');
        $tasks = $this->sheets->getRows($sheetId, 'Task_Queue');
        $outreach = $this->sheets->getRows($sheetId, 'Outreach_Log');
        $igQueue = $this->sheets->getRows($sheetId, 'IG_Profile_URL_Queue');
        $ttQueue = $this->sheets->getRows($sheetId, 'TikTok_Profile_URL_Queue');

        $today = now()->toDateString();
        $metrics = [
            'creators_discovered' => count(array_filter(array_merge($igQueue, $ttQueue), fn (array $row) => trim((string) ($row['handle'] ?? '')) !== '')),
            'creators_in_crm' => count(array_filter($crm, fn (array $row) => trim((string) ($row['Handle'] ?? '')) !== '')),
            'ready_for_outreach' => count(array_filter($crm, fn (array $row) => strtoupper(trim((string) ($row['Status'] ?? ''))) === 'NEW')),
            'tasks_due_today' => count(array_filter($tasks, fn (array $row) => str_starts_with((string) ($row['Due_At'] ?? ''), $today))),
            'open_tasks' => count(array_filter($tasks, function (array $row) {
                $status = strtoupper(trim((string) ($row['Status'] ?? '')));
                return !in_array($status, ['DONE', 'COMPLETED', 'SKIPPED'], true);
            })),
            'outreach_sent' => count(array_filter($outreach, fn (array $row) => trim((string) ($row['Event_ID'] ?? '')) !== '')),
            'responses_received' => count(array_filter($crm, fn (array $row) => trim((string) ($row['Response_Date'] ?? '')) !== '')),
            'accepted_creators' => count(array_filter($crm, fn (array $row) => strtoupper(trim((string) ($row['Accepted_(Y/N)'] ?? 'N'))) === 'Y')),
        ];

        return response()->json([
            'message' => 'Dashboard metrics fetched',
            'items' => [$metrics],
            'metrics' => $metrics,
        ]);
    }

    private function appendDiscoveryPostsToQueues(string $sheetId, array $postIds): array
    {
        $resolved = $this->resolveDiscoveryRows($sheetId, $postIds);
        $queueHeaders = $this->sheets->getHeaders($sheetId, 'Profile_URL_Queue_All');
        $platformHeaders = [
            'instagram' => $this->sheets->getHeaders($sheetId, 'IG_Profile_URL_Queue'),
            'tiktok' => $this->sheets->getHeaders($sheetId, 'TikTok_Profile_URL_Queue'),
        ];

        $existingAll = $this->queueKeyMap($this->sheets->getRows($sheetId, 'Profile_URL_Queue_All'));
        $existingInstagram = $this->queueKeyMap($this->sheets->getRows($sheetId, 'IG_Profile_URL_Queue'));
        $existingTikTok = $this->queueKeyMap($this->sheets->getRows($sheetId, 'TikTok_Profile_URL_Queue'));

        $toAppendAll = [];
        $toAppendInstagram = [];
        $toAppendTikTok = [];
        $items = [];
        $created = 0;
        $skipped = 0;

        foreach ($resolved as $resolvedRow) {
            $queueRow = $this->mapDiscoveryRowToQueue($resolvedRow['platform'], $resolvedRow['row']);
            $queueKey = $this->queueKey($queueRow);

            if ($queueKey === '') {
                $skipped++;
                continue;
            }

            $existsInAll = isset($existingAll[$queueKey]);
            $existsInPlatform = $resolvedRow['platform'] === 'instagram'
                ? isset($existingInstagram[$queueKey])
                : isset($existingTikTok[$queueKey]);

            if (!$existsInAll) {
                $toAppendAll[] = $queueRow;
                $existingAll[$queueKey] = true;
            }

            if ($resolvedRow['platform'] === 'instagram' && !$existsInPlatform) {
                $toAppendInstagram[] = $queueRow;
                $existingInstagram[$queueKey] = true;
            }

            if ($resolvedRow['platform'] === 'tiktok' && !$existsInPlatform) {
                $toAppendTikTok[] = $queueRow;
                $existingTikTok[$queueKey] = true;
            }

            if (!$existsInAll || !$existsInPlatform) {
                $created++;
                $items[] = $queueRow;
            } else {
                $skipped++;
            }
        }

        if ($toAppendAll !== []) {
            $this->sheets->appendAssocRows($sheetId, 'Profile_URL_Queue_All', $toAppendAll, $queueHeaders);
        }

        if ($toAppendInstagram !== []) {
            $this->sheets->appendAssocRows($sheetId, 'IG_Profile_URL_Queue', $toAppendInstagram, $platformHeaders['instagram']);
        }

        if ($toAppendTikTok !== []) {
            $this->sheets->appendAssocRows($sheetId, 'TikTok_Profile_URL_Queue', $toAppendTikTok, $platformHeaders['tiktok']);
        }

        return [
            'items' => $items,
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    private function resolveDiscoveryRows(string $sheetId, array $postIds): array
    {
        $instagramRows = $this->indexByRowNumber($this->sheets->getRows($sheetId, 'Instagram_Posts_Raw'));
        $tiktokRows = $this->indexByRowNumber($this->sheets->getRows($sheetId, 'TikTok_Posts_Raw'));
        $resolved = [];

        foreach ($postIds as $postId) {
            $parsed = $this->parseDiscoveryPostId($postId);
            $platform = $parsed['platform'];
            $rowNumber = $parsed['rowNumber'];

            if ($rowNumber === null) {
                continue;
            }

            if (($platform === 'instagram' || $platform === null) && isset($instagramRows[$rowNumber])) {
                $resolved[] = [
                    'platform' => 'instagram',
                    'row' => $instagramRows[$rowNumber],
                ];
                continue;
            }

            if (($platform === 'tiktok' || $platform === null) && isset($tiktokRows[$rowNumber])) {
                $resolved[] = [
                    'platform' => 'tiktok',
                    'row' => $tiktokRows[$rowNumber],
                ];
            }
        }

        return $resolved;
    }

    private function parseDiscoveryPostId(mixed $postId): array
    {
        $raw = trim((string) $postId);
        $platform = null;
        $rowNumber = null;

        if (is_numeric($raw)) {
            $rowNumber = (int) $raw;
        } elseif (preg_match('/^(instagram|tiktok)[:|\-](\d+)$/i', $raw, $matches) === 1) {
            $platform = strtolower($matches[1]);
            $rowNumber = (int) $matches[2];
        } elseif (preg_match('/(\d+)$/', $raw, $matches) === 1) {
            $rowNumber = (int) $matches[1];
            if (str_contains(strtolower($raw), 'instagram')) {
                $platform = 'instagram';
            }
            if (str_contains(strtolower($raw), 'tiktok')) {
                $platform = 'tiktok';
            }
        }

        return [
            'platform' => $platform,
            'rowNumber' => $rowNumber,
        ];
    }

    private function mapDiscoveryRowToQueue(string $platform, array $row): array
    {
        if ($platform === 'instagram') {
            $username = ltrim(trim((string) ($row['ownerUsername'] ?? '')), '@');
            $handle = $username === '' ? '' : '@' . $username;
            $url = $username === '' ? '' : 'https://www.instagram.com/' . $username . '/';

            return [
                'platform' => 'Instagram',
                'handle' => $handle,
                'url' => $url,
                'username' => $username,
                'name' => (string) ($row['ownerFullName'] ?? ''),
                'country' => (string) ($row['Country_Guess'] ?? ''),
                'city' => (string) ($row['City_Guess'] ?? ''),
                'primary_language' => (string) ($row['Primary_Language_Guess'] ?? ''),
                'niche_category' => (string) ($row['Post_Niche'] ?? ''),
                'status' => 'NEW',
                'priority_for_enrichment' => 'Y',
                'source_notes' => trim('From discovery raw | ' . (string) ($row['Reason_Summary'] ?? '')),
            ];
        }

        $username = ltrim(trim((string) ($row['authorMeta.name'] ?? '')), '@');
        $handle = $username === '' ? '' : '@' . $username;
        $url = $username === '' ? '' : 'https://www.tiktok.com/@' . $username;

        return [
            'platform' => 'TikTok',
            'handle' => $handle,
            'url' => $url,
            'username' => $username,
            'name' => '',
            'country' => '',
            'city' => '',
            'primary_language' => '',
            'niche_category' => '',
            'status' => 'NEW',
            'priority_for_enrichment' => 'Y',
            'source_notes' => trim('From discovery raw | ' . Str::limit((string) ($row['text'] ?? ''), 200, '')),
        ];
    }

    private function queueKeyMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $key = $this->queueKey($row);
            if ($key !== '') {
                $map[$key] = true;
            }
        }

        return $map;
    }

    private function queueKey(array $row): string
    {
        $platform = strtolower(trim((string) ($row['platform'] ?? '')));
        $handle = strtolower(trim((string) ($row['handle'] ?? '')));
        $url = strtolower(trim((string) ($row['url'] ?? '')));

        if ($platform === '' && $handle === '' && $url === '') {
            return '';
        }

        return $platform . '|' . ($handle !== '' ? $handle : $url);
    }

    private function normalizeMessageTemplate(array $template, bool $fillMissing = true): array
    {
        $record = [];
        $record['Angle_Name'] = (string) ($template['Angle_Name'] ?? $template['angle_name'] ?? $template['angleName'] ?? $template['id'] ?? '');
        $record['DM_Template'] = (string) ($template['DM_Template'] ?? $template['dm_template'] ?? $template['dmTemplate'] ?? $template['copy'] ?? $template['template'] ?? '');
        $record['Best_For_Platform'] = (string) ($template['Best_For_Platform'] ?? $template['best_for_platform'] ?? $template['bestForPlatform'] ?? $template['platform'] ?? '');
        $record['Psychological_Trigger'] = (string) ($template['Psychological_Trigger'] ?? $template['psychological_trigger'] ?? $template['psychologicalTrigger'] ?? $template['notes'] ?? '');

        if ($fillMissing) {
            foreach (['Angle_Name', 'DM_Template', 'Best_For_Platform', 'Psychological_Trigger'] as $key) {
                $record[$key] ??= '';
            }
        }

        return $record;
    }

    private function resolveMessageRowNumber(string $sheetId, string $id): ?int
    {
        if (is_numeric($id)) {
            return (int) $id;
        }

        foreach ($this->sheets->getRows($sheetId, 'Message_Library') as $row) {
            if ((string) ($row['Angle_Name'] ?? '') === $id) {
                return (int) ($row['_row_number'] ?? 0);
            }
        }

        return null;
    }

    private function findRowByRowNumber(string $sheetId, string $sheetName, int $rowNumber): ?array
    {
        foreach ($this->sheets->getRows($sheetId, $sheetName) as $row) {
            if ((int) ($row['_row_number'] ?? 0) === $rowNumber) {
                return $row;
            }
        }

        return null;
    }

    private function indexByRowNumber(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $rowNumber = (int) ($row['_row_number'] ?? 0);
            if ($rowNumber > 0) {
                $indexed[$rowNumber] = $row;
            }
        }

        return $indexed;
    }

    private function resolveSheetId(?string $sheetId): string
    {
        $resolved = trim((string) ($sheetId ?? ''));
        if ($resolved !== '') {
            return $resolved;
        }

        return (string) config('services.google.default_sheet_id');
    }
}
