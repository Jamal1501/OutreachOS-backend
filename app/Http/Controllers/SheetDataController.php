<?php

namespace App\Http\Controllers;

use App\Services\CreatorMergeService;
use App\Services\GoogleSheetsService;
use App\Services\InfluencerScoringService;
use App\Services\TaskQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class SheetDataController extends Controller
{
    public function __construct(
        private GoogleSheetsService $sheets,
        private CreatorMergeService $creatorMerge,
        private TaskQueueService $taskQueue,
        private InfluencerScoringService $scoring,
    ) {
    }

    public function discoveryList(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', Rule::in(['instagram', 'tiktok'])],
            'search' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'dedupe' => ['nullable', 'boolean'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $platforms = isset($validated['platform']) ? [$validated['platform']] : ['instagram', 'tiktok'];
        $search = Str::lower(trim((string) ($validated['search'] ?? '')));
        $limit = (int) ($validated['limit'] ?? 200);
        $offset = (int) ($validated['offset'] ?? 0);
        $dedupe = $request->boolean('dedupe', true);

        $normalized = [];
        foreach ($platforms as $platform) {
            $sheetName = $platform === 'instagram' ? 'Instagram_Posts_Raw' : 'TikTok_Posts_Raw';
            foreach ($this->sheets->getRows($sheetId, $sheetName) as $row) {
                $item = $this->normalizeDiscoveryRow($platform, $sheetName, $row);
                if ($item === null) {
                    continue;
                }
                if ($search !== '' && !$this->matchesDiscoverySearch($item, $search)) {
                    continue;
                }
                $normalized[] = $item;
            }
        }

        usort($normalized, fn (array $a, array $b) => strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? '')));
        $rawTotal = count($normalized);

        if ($dedupe) {
            $groups = [];
            foreach ($normalized as $item) {
                $groups[$item['duplicateKey']][] = $item;
            }

            $deduped = [];
            $duplicateGroups = 0;
            foreach ($groups as $items) {
                if (count($items) > 1) {
                    $duplicateGroups++;
                }
                $deduped[] = $this->collapseDuplicateGroup($items);
            }
            $normalized = $deduped;
        } else {
            $duplicateGroups = 0;
        }

        $total = count($normalized);
        $items = array_slice($normalized, $offset, $limit);

        return response()->json([
            'message' => 'Discovery rows fetched',
            'items' => array_values($items),
            'total' => $total,
            'raw_total' => $rawTotal,
            'deduped' => $dedupe,
            'duplicate_groups' => $duplicateGroups,
        ]);
    }

    public function extractUrls(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'postIds' => ['required', 'array', 'min:1'],
            'postIds.*' => ['string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $result = $this->queueDiscoveryPosts($sheetId, $validated['postIds'], 'DISCOVERY_EXTRACT');

        return response()->json([
            'message' => 'Selected discovery rows extracted to queue',
            'extracted' => $result['created'],
            'skipped' => $result['skipped'],
            'items' => $result['items'],
        ]);
    }

    public function pushToEnrichment(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'postIds' => ['required', 'array', 'min:1'],
            'postIds.*' => ['string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $result = $this->queueDiscoveryPosts($sheetId, $validated['postIds'], 'DISCOVERY_PUSH');

        return response()->json([
            'message' => 'Selected discovery rows pushed to enrichment queue',
            'pushed' => $result['created'],
            'skipped' => $result['skipped'],
            'items' => $result['items'],
        ]);
    }

    public function crmList(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'platform' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'niche' => ['nullable', 'string'],
            'added_from' => ['nullable', 'string'],
            'added_to' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $search = Str::lower(trim((string) ($validated['search'] ?? '')));
        $platform = Str::lower(trim((string) ($validated['platform'] ?? '')));
        $status = Str::lower(trim((string) ($validated['status'] ?? '')));
        $niche = Str::lower(trim((string) ($validated['niche'] ?? '')));
        $addedFrom = trim((string) ($validated['added_from'] ?? ''));
        $addedTo = trim((string) ($validated['added_to'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 200);
        $offset = (int) ($validated['offset'] ?? 0);

        $items = [];
        foreach ($this->sheets->getRows($sheetId, 'Creators_CRM') as $row) {
            $item = $this->normalizeCreatorRow($row);

            if ($search !== '' && !$this->matchesTextSearch($search, [
                $item['handle'] ?? '',
                $item['fullName'] ?? '',
                $item['niche'] ?? '',
                $item['email'] ?? '',
            ])) {
                continue;
            }

            if ($platform !== '' && Str::lower((string) ($item['platform'] ?? '')) !== $platform) {
                continue;
            }

            if ($status !== '' && Str::lower((string) ($item['status'] ?? '')) !== $status) {
                continue;
            }

            if ($niche !== '' && Str::lower((string) ($item['niche'] ?? '')) !== $niche) {
                continue;
            }

            if (!$this->matchesDateRange((string) ($item['addedAt'] ?? ''), $addedFrom, $addedTo)) {
                continue;
            }

            $items[] = $item;
        }

        usort($items, fn (array $a, array $b) => strcmp((string) ($b['addedAt'] ?? ''), (string) ($a['addedAt'] ?? '')));
        $total = count($items);

        return response()->json([
            'message' => 'Creators fetched',
            'items' => array_values(array_slice($items, $offset, $limit)),
            'total' => $total,
        ]);
    }

    public function updateCreator(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'creator' => ['required', 'array'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $rowNumber = $this->parseRowNumber($id, 'crm');
        $rows = $this->sheets->getRows($sheetId, 'Creators_CRM');
        $target = collect($rows)->first(fn (array $row) => (int) ($row['_row_number'] ?? 0) === $rowNumber);

        if (!$target) {
            return response()->json(['error' => 'Creator not found'], 404);
        }

        $payload = $validated['creator'];
        foreach (['niche', 'status', 'notes', 'email', 'fullName'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            match ($field) {
                'niche' => $target['Niche_Category'] = (string) $payload[$field],
                'status' => $target['Status'] = (string) $payload[$field],
                'notes' => $target['Notes'] = (string) $payload[$field],
                'email' => $target['Contact_Email'] = (string) $payload[$field],
                'fullName' => $target['Name'] = (string) $payload[$field],
            };
        }

        $score = $this->scoring->score($target);
        $target['Value_Score'] = (string) $score;
        $target['Value_Bar'] = $this->scoring->bar($score);
        $target['Preferred_Channel'] = trim((string) ($target['Contact_Email'] ?? '')) !== '' ? 'Email' : 'DM';

        $this->sheets->updateAssocRow($sheetId, 'Creators_CRM', $rowNumber, $target);

        return response()->json([
            'message' => 'Creator updated',
            'item' => $this->normalizeCreatorRow($target),
        ]);
    }

    public function mergeSelectedQueueToCrm(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['required', 'string', Rule::in(['instagram', 'tiktok'])],
            'queueIds' => ['required', 'array', 'min:1'],
            'queueIds.*' => ['string'],
            'createTasks' => ['nullable', 'boolean'],
            'taskLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $platform = $validated['platform'];
        $queueSheet = $this->queueSheetForPlatform($platform);
        $sourceSheet = $this->enrichedSheetForPlatform($platform);

        $queueRows = $this->sheets->getRows($sheetId, $queueSheet);
        $selectedQueueRowNumbers = array_map(fn (string $id) => $this->parseQueueId($id, $platform), $validated['queueIds']);
        $selectedLookup = array_fill_keys($selectedQueueRowNumbers, true);
        $selectedQueueRows = array_values(array_filter($queueRows, fn (array $row) => isset($selectedLookup[(int) ($row['_row_number'] ?? 0)])));

        $sourceRows = $this->sheets->getRows($sheetId, $sourceSheet);
        $sourceRowNumbers = $this->matchQueueRowsToEnrichedRowNumbers($platform, $selectedQueueRows, $sourceRows);

        $result = $this->creatorMerge->mergeSelectedFromEnrichedSheet($sheetId, $sourceSheet, $sourceRowNumbers);
        $result['selectedQueueCount'] = count($selectedQueueRows);

        if (($validated['createTasks'] ?? false) === true) {
            $result['taskGeneration'] = $this->taskQueue->generateInitialTasks($sheetId, [
                'limit' => $validated['taskLimit'] ?? 50,
            ]);
        }

        return response()->json([
            'message' => 'Selected queue rows merged into Creators_CRM',
            'result' => $result,
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
        $platform = Str::lower(trim((string) ($validated['platform'] ?? '')));
        $stage = Str::lower(trim((string) ($validated['stage'] ?? '')));
        $niche = Str::lower(trim((string) ($validated['niche'] ?? '')));

        $items = [];
        foreach ($this->sheets->getRows($sheetId, 'Message_Library') as $row) {
            $item = $this->normalizeMessageRow($row);
            if ($platform !== '' && Str::lower((string) $item['platform']) !== $platform) {
                continue;
            }
            if ($stage !== '' && Str::lower((string) $item['stage']) !== $stage) {
                continue;
            }
            if ($niche !== '' && Str::lower((string) $item['niche']) !== $niche) {
                continue;
            }
            $items[] = $item;
        }

        return response()->json([
            'message' => 'Message templates fetched',
            'items' => $items,
        ]);
    }

    public function createMessage(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'template' => ['required', 'array'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $template = $validated['template'];
        $headers = $this->sheets->getHeaders($sheetId, 'Message_Library');
        $row = $this->messagePayloadToSheetRecord($template);
        $this->sheets->appendAssocRows($sheetId, 'Message_Library', [$row], $headers);

        $rows = $this->sheets->getRows($sheetId, 'Message_Library');
        $id = 'msg:' . ((int) ($rows[count($rows) - 1]['_row_number'] ?? 2));

        return response()->json([
            'message' => 'Message template created',
            'id' => $id,
        ]);
    }

    public function updateMessage(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'template' => ['required', 'array'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $rowNumber = $this->parseRowNumber($id, 'msg');
        $record = $this->messagePayloadToSheetRecord($validated['template']);
        $this->sheets->updateAssocRow($sheetId, 'Message_Library', $rowNumber, $record);

        return response()->json(['message' => 'Message template updated']);
    }

    public function deleteMessage(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $rowNumber = $this->parseRowNumber($id, 'msg');
        $this->sheets->clearAssocRow($sheetId, 'Message_Library', $rowNumber);

        return response()->json(['message' => 'Message template deleted']);
    }

    public function enrichmentQueue(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', Rule::in(['instagram', 'tiktok'])],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $platform = $validated['platform'] ?? null;
        $platforms = $platform ? [$platform] : ['instagram', 'tiktok'];
        $items = [];

        foreach ($platforms as $platformName) {
            $queueSheet = $this->queueSheetForPlatform($platformName);
            $sourceSheet = $this->enrichedSheetForPlatform($platformName);
            $sourceRows = $this->sheets->getRows($sheetId, $sourceSheet);
            $sourceIndex = $this->buildEnrichedLookup($platformName, $sourceRows);

            foreach ($this->sheets->getRows($sheetId, $queueSheet) as $row) {
                $handle = $this->normalizeHandle((string) ($row['handle'] ?? $row['username'] ?? ''));
                $url = trim((string) ($row['url'] ?? ''));
                $lookupKeys = array_filter([
                    strtolower($handle),
                    strtolower(trim($url)),
                    strtolower(trim((string) ($row['username'] ?? ''))),
                ]);
                $enrichedRowNumber = null;
                foreach ($lookupKeys as $lookupKey) {
                    if (isset($sourceIndex[$lookupKey])) {
                        $enrichedRowNumber = (int) $sourceIndex[$lookupKey];
                        break;
                    }
                }

                $rowStatus = strtolower(trim((string) ($row['status'] ?? 'queued')));
                if ($enrichedRowNumber !== null) {
                    $rowStatus = 'enriched';
                }

                $items[] = [
                    'id' => $this->queueId($platformName, (int) ($row['_row_number'] ?? 0)),
                    'rowId' => $this->queueId($platformName, (int) ($row['_row_number'] ?? 0)),
                    'platform' => $platformName,
                    'handle' => $handle,
                    'profileUrl' => $url,
                    'status' => $rowStatus,
                    'sourceNotes' => (string) ($row['source_notes'] ?? ''),
                    'addedAt' => $this->extractTaggedValue((string) ($row['source_notes'] ?? ''), 'added_at') ?? '',
                    'readyToMerge' => $enrichedRowNumber !== null,
                    'enrichedRowNumber' => $enrichedRowNumber,
                    'sourceSheet' => $sourceSheet,
                ];
            }
        }

        usort($items, fn (array $a, array $b) => strcmp((string) ($b['addedAt'] ?? ''), (string) ($a['addedAt'] ?? '')));

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
        $creatorRows = $this->sheets->getRows($sheetId, 'Creators_CRM');
        $creators = array_map(fn (array $row) => $this->normalizeCreatorRow($row), $creatorRows);
        $tasks = $this->sheets->getRows($sheetId, 'Task_Queue');
        $outreach = $this->sheets->getRows($sheetId, 'Outreach_Log');
        $discoveredHandles = [];

        foreach ([['instagram', 'Instagram_Posts_Raw', 'ownerUsername'], ['tiktok', 'TikTok_Posts_Raw', 'authorMeta.name']] as [$platformKey, $sheetName, $handleKey]) {
            foreach ($this->sheets->getRows($sheetId, $sheetName) as $row) {
                $handle = strtolower(trim((string) ($row[$handleKey] ?? '')));
                if ($handle === '') {
                    continue;
                }
                $discoveredHandles[$platformKey . '|' . $handle] = true;
            }
        }

        $today = now()->toDateString();
        $metrics = [
            'creatorsDiscovered' => count($discoveredHandles),
            'creatorsEnriched' => count(array_filter($creators, fn (array $row) => ($row['enrichmentStatus'] ?? 'pending') === 'enriched')),
            'readyForOutreach' => count(array_filter($creators, fn (array $row) => in_array((string) ($row['status'] ?? 'discovered'), ['discovered', 'enriched'], true))),
            'tasksDueToday' => count(array_filter($tasks, fn (array $row) => str_starts_with((string) ($row['Due_At'] ?? ''), $today) && !in_array(strtoupper((string) ($row['Status'] ?? '')), ['DONE', 'COMPLETED', 'SKIPPED'], true))),
            'outreachSent' => count(array_filter($outreach, fn (array $row) => Str::contains(strtoupper((string) ($row['Event_Type'] ?? '')), ['SENT']))),
            'repliesReceived' => count(array_filter($outreach, fn (array $row) => Str::contains(strtoupper((string) ($row['Event_Type'] ?? '')), ['REPLY', 'ACCEPTED']))),
            'scrapeSpend' => 0,
        ];

        return response()->json([
            'message' => 'Dashboard metrics fetched',
            'metrics' => $metrics,
        ]);
    }

    private function resolveSheetId(?string $sheetId): string
    {
        $resolved = trim((string) ($sheetId ?: config('services.google.default_sheet_id')));
        if ($resolved === '') {
            throw new RuntimeException('Missing sheetId and GOOGLE_DEFAULT_SHEET_ID');
        }
        return $resolved;
    }

    private function normalizeDiscoveryRow(string $platform, string $sheetName, array $row): ?array
    {
        $postUrl = trim((string) ($row['url'] ?? $row['webVideoUrl'] ?? ''));
        $caption = trim((string) ($row['caption'] ?? $row['text'] ?? ''));
        $handle = $platform === 'instagram'
            ? $this->normalizeHandle((string) ($row['ownerUsername'] ?? ''))
            : $this->normalizeHandle((string) ($row['authorMeta.name'] ?? ''));
        $authorUrl = $platform === 'instagram'
            ? $this->instagramProfileUrl((string) ($row['ownerUsername'] ?? ''))
            : $this->tiktokProfileUrl((string) ($row['authorMeta.name'] ?? ''));

        if ($postUrl === '' && $handle === '' && $caption === '') {
            return null;
        }

        $likes = $this->sanitizeMetric($row['likesCount'] ?? $row['diggCount'] ?? null);
        $comments = $this->sanitizeMetric($row['commentsCount'] ?? $row['commentCount'] ?? null);
        $views = $this->sanitizeMetric($row['playCount'] ?? null);
        $rowNumber = (int) ($row['_row_number'] ?? 0);
        $duplicateKey = strtolower(trim(($postUrl !== '' ? $postUrl : ($platform . '|' . $handle . '|' . $caption))));

        return [
            'id' => $platform . ':' . $rowNumber,
            'rowId' => $platform . ':' . $rowNumber,
            'sourceSheet' => $sheetName,
            'sourceRowNumber' => $rowNumber,
            'platform' => $platform,
            'authorHandle' => $handle,
            'authorUrl' => $authorUrl,
            'caption' => $caption,
            'likes' => $likes,
            'comments' => $comments,
            'views' => $views,
            'postUrl' => $postUrl,
            'timestamp' => (string) ($row['timestamp'] ?? $row['createTimeISO'] ?? ''),
            'duplicateKey' => $duplicateKey,
            'raw' => $row,
        ];
    }

    private function collapseDuplicateGroup(array $items): array
    {
        usort($items, function (array $a, array $b) {
            $scoreA = ($a['likes'] ?? 0) + ($a['comments'] ?? 0) + ($a['views'] ?? 0);
            $scoreB = ($b['likes'] ?? 0) + ($b['comments'] ?? 0) + ($b['views'] ?? 0);
            if ($scoreA === $scoreB) {
                return strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? ''));
            }
            return $scoreB <=> $scoreA;
        });

        $primary = $items[0];
        $metricFingerprints = array_unique(array_map(function (array $item) {
            return implode('|', [
                $item['likes'] ?? '',
                $item['comments'] ?? '',
                $item['views'] ?? '',
            ]);
        }, $items));

        $primary['duplicateCount'] = count($items);
        $primary['duplicateIds'] = array_values(array_map(fn (array $item) => $item['id'], $items));
        $primary['hasMetricMismatch'] = count($metricFingerprints) > 1;

        return $primary;
    }

    private function queueDiscoveryPosts(string $sheetId, array $postIds, string $actionTag): array
    {
        $queueRecordsBySheet = [
            'IG_Profile_URL_Queue' => [],
            'TikTok_Profile_URL_Queue' => [],
            'Profile_URL_Queue_All' => [],
        ];

        $existingLookup = [];
        foreach (['IG_Profile_URL_Queue', 'TikTok_Profile_URL_Queue', 'Profile_URL_Queue_All'] as $sheetName) {
            foreach ($this->sheets->getRows($sheetId, $sheetName) as $row) {
                $key = strtolower(trim((string) ($row['platform'] ?? ''))) . '|' . strtolower(trim((string) ($row['handle'] ?? ''))) . '|' . strtolower(trim((string) ($row['url'] ?? '')));
                $existingLookup[$sheetName][$key] = true;
            }
        }

        $createdItems = [];
        $created = 0;
        $skipped = 0;

        foreach ($postIds as $postId) {
            [$platform, $rowNumber] = $this->parseDiscoveryId($postId);
            $sheetName = $platform === 'instagram' ? 'Instagram_Posts_Raw' : 'TikTok_Posts_Raw';
            $rawRow = collect($this->sheets->getRows($sheetId, $sheetName))
                ->first(fn (array $row) => (int) ($row['_row_number'] ?? 0) === $rowNumber);

            if (!$rawRow) {
                $skipped++;
                continue;
            }

            $queueRecord = $this->discoveryRowToQueueRecord($platform, $rawRow, $actionTag);
            if ($queueRecord === null) {
                $skipped++;
                continue;
            }

            $sheetTarget = $this->queueSheetForPlatform($platform);
            $key = strtolower($queueRecord['platform']) . '|' . strtolower($queueRecord['handle']) . '|' . strtolower($queueRecord['url']);

            if (isset($existingLookup[$sheetTarget][$key])) {
                $skipped++;
                continue;
            }

            $queueRecordsBySheet[$sheetTarget][] = $queueRecord;
            $queueRecordsBySheet['Profile_URL_Queue_All'][] = $queueRecord;
            $existingLookup[$sheetTarget][$key] = true;
            $existingLookup['Profile_URL_Queue_All'][$key] = true;
            $createdItems[] = $queueRecord;
            $created++;
        }

        foreach ($queueRecordsBySheet as $sheetName => $records) {
            if (count($records) > 0) {
                $this->sheets->appendAssocRows($sheetId, $sheetName, $records);
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'items' => $createdItems,
        ];
    }

    private function discoveryRowToQueueRecord(string $platform, array $row, string $actionTag): ?array
    {
        $username = $platform === 'instagram'
            ? trim((string) ($row['ownerUsername'] ?? ''))
            : trim((string) ($row['authorMeta.name'] ?? ''));
        $handle = $this->normalizeHandle($username);
        $url = $platform === 'instagram' ? $this->instagramProfileUrl($username) : $this->tiktokProfileUrl($username);

        if ($handle === '' && $url === '') {
            return null;
        }

        $addedAt = now()->toDateTimeString();

        return [
            'platform' => $platform,
            'handle' => $handle,
            'url' => $url,
            'username' => $username,
            'name' => (string) ($row['ownerFullName'] ?? ''),
            'country' => (string) ($row['Country_Guess'] ?? ''),
            'city' => (string) ($row['City_Guess'] ?? ''),
            'primary_language' => (string) ($row['Primary_Language_Guess'] ?? ''),
            'niche_category' => (string) ($row['Post_Niche'] ?? ''),
            'status' => 'queued',
            'priority_for_enrichment' => 'normal',
            'source_notes' => sprintf('%s; added_at=%s; source_post_row=%s; source_post_url=%s', $actionTag, $addedAt, (string) ($row['_row_number'] ?? ''), (string) ($row['url'] ?? $row['webVideoUrl'] ?? '')),
        ];
    }

    private function normalizeCreatorRow(array $row): array
    {
        $platform = Str::lower((string) ($row['Platform'] ?? 'instagram'));
        $enrichmentStatus = $this->normalizeEnrichmentStatus($row);
        $status = $this->normalizeCreatorStatus((string) ($row['Status'] ?? ''), $enrichmentStatus);
        $score = is_numeric((string) ($row['Value_Score'] ?? '')) ? (float) $row['Value_Score'] : $this->scoring->score($row);
        $addedAt = $this->extractTaggedValue((string) ($row['Notes'] ?? ''), 'added_to_crm_at') ?? '';

        return [
            'id' => 'crm:' . (int) ($row['_row_number'] ?? 0),
            'rowId' => 'crm:' . (int) ($row['_row_number'] ?? 0),
            'platform' => $platform,
            'handle' => (string) ($row['Handle'] ?? ''),
            'fullName' => (string) ($row['Name'] ?? ''),
            'followers' => $this->sanitizeMetric($row['Followers'] ?? null),
            'engagementRate' => $this->sanitizeFloat($row['Engagement_Rate_%'] ?? null),
            'email' => (string) ($row['Contact_Email'] ?? ''),
            'status' => $status,
            'enrichmentStatus' => $enrichmentStatus,
            'profileUrl' => (string) ($row['DM_Link'] ?? ''),
            'dmUrl' => (string) ($row['DM_Link'] ?? ''),
            'niche' => (string) ($row['Niche_Category'] ?? ''),
            'lastContactDate' => (string) ($row['DM_Sent_Date'] ?? ''),
            'notes' => (string) ($row['Notes'] ?? ''),
            'addedAt' => $addedAt,
            'valueScore' => (int) round($score),
            'valueTier' => Str::lower($this->scoring->tier($score)),
            'preferredChannel' => (string) ($row['Preferred_Channel'] ?? ''),
        ];
    }

    private function normalizeCreatorStatus(string $status, string $enrichmentStatus = 'pending'): string
    {
        return match (Str::upper(trim($status))) {
            'QUEUED' => 'queued',
            'CONTACTED', 'FOLLOW_REQUEST_SENT', 'FOLLOWED_UP' => 'contacted',
            'REPLIED' => 'replied',
            'ACCEPTED' => 'accepted',
            'DECLINED' => 'declined',
            'ARCHIVED' => 'archived',
            default => $enrichmentStatus === 'enriched' ? 'enriched' : 'discovered',
        };
    }

    private function normalizeEnrichmentStatus(array $row): string
    {
        $rawStatus = Str::upper(trim((string) ($row['Status'] ?? '')));
        $notes = (string) ($row['Notes'] ?? '');
        $sourceTag = Str::lower((string) ($this->extractTaggedValue($notes, 'source') ?? ''));

        if (in_array($rawStatus, ['FAILED', 'ENRICHMENT_FAILED', 'FAILED_ENRICHMENT'], true)
            || Str::contains(Str::lower($notes), ['enrichment_failed', 'enrichment failed'])) {
            return 'failed';
        }

        $hasEnrichmentData = trim((string) ($row['Followers'] ?? '')) !== ''
            || trim((string) ($row['Engagement_Rate_%'] ?? '')) !== ''
            || trim((string) ($row['Contact_Email'] ?? '')) !== '';

        $statusImpliesEnriched = in_array($rawStatus, [
            'NEW',
            'ENRICHED',
            'CONTACTED',
            'FOLLOW_REQUEST_SENT',
            'FOLLOWED_UP',
            'REPLIED',
            'ACCEPTED',
            'DECLINED',
            'ARCHIVED',
        ], true);

        if ($hasEnrichmentData
            || in_array($sourceTag, ['ig_enriched', 'tiktok_enriched'], true)
            || $statusImpliesEnriched) {
            return 'enriched';
        }

        return 'pending';
    }

    private function normalizeMessageRow(array $row): array
    {
        $meta = $this->parseMessageMeta((string) ($row['Psychological_Trigger'] ?? ''));

        return [
            'id' => 'msg:' . (int) ($row['_row_number'] ?? 0),
            'angleId' => (string) ($row['Angle_Name'] ?? ''),
            'platform' => Str::lower((string) ($row['Best_For_Platform'] ?? 'instagram')),
            'niche' => (string) ($meta['niche'] ?? ''),
            'stage' => (string) ($meta['stage'] ?? 'cold_invite'),
            'copy' => (string) ($row['DM_Template'] ?? ''),
            'notes' => (string) ($meta['notes'] ?? ''),
            'psychologicalTrigger' => (string) ($meta['trigger'] ?? ''),
        ];
    }

    private function messagePayloadToSheetRecord(array $template): array
    {
        $trigger = trim((string) ($template['psychologicalTrigger'] ?? $template['trigger'] ?? ''));
        $meta = [
            'stage' => (string) ($template['stage'] ?? 'cold_invite'),
            'niche' => (string) ($template['niche'] ?? ''),
            'notes' => (string) ($template['notes'] ?? ''),
        ];

        return [
            'Angle_Name' => (string) ($template['angleId'] ?? ''),
            'DM_Template' => (string) ($template['copy'] ?? ''),
            'Best_For_Platform' => (string) ($template['platform'] ?? 'instagram'),
            'Psychological_Trigger' => $trigger . ' || META:' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
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

    private function buildEnrichedLookup(string $platform, array $sourceRows): array
    {
        $lookup = [];
        foreach ($sourceRows as $row) {
            $keys = $this->enrichedLookupKeys($platform, $row);
            foreach ($keys as $key) {
                $lookup[$key] = (int) ($row['_row_number'] ?? 0);
            }
        }
        return $lookup;
    }

    private function matchQueueRowsToEnrichedRowNumbers(string $platform, array $queueRows, array $sourceRows): array
    {
        $lookup = $this->buildEnrichedLookup($platform, $sourceRows);
        $rowNumbers = [];

        foreach ($queueRows as $row) {
            foreach ($this->queueLookupKeys($platform, $row) as $key) {
                if (isset($lookup[$key])) {
                    $rowNumbers[] = (int) $lookup[$key];
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($rowNumbers)));
    }

    private function enrichedLookupKeys(string $platform, array $row): array
    {
        return array_values(array_unique(array_filter([
            strtolower(trim((string) ($row['profile_url'] ?? ''))),
            strtolower(trim((string) ($row['input_url'] ?? ''))),
            strtolower($this->normalizeHandle((string) ($row['handle'] ?? $row['username'] ?? ''))),
            strtolower(trim((string) ($row['username'] ?? ''))),
        ])));
    }

    private function queueLookupKeys(string $platform, array $row): array
    {
        return array_values(array_unique(array_filter([
            strtolower(trim((string) ($row['url'] ?? ''))),
            strtolower($this->normalizeHandle((string) ($row['handle'] ?? $row['username'] ?? ''))),
            strtolower(trim((string) ($row['username'] ?? ''))),
        ])));
    }

    private function matchesDiscoverySearch(array $item, string $search): bool
    {
        return $this->matchesTextSearch($search, [
            $item['authorHandle'] ?? '',
            $item['caption'] ?? '',
            $item['postUrl'] ?? '',
        ]);
    }

    private function matchesTextSearch(string $search, array $values): bool
    {
        foreach ($values as $value) {
            if (Str::contains(Str::lower((string) $value), $search)) {
                return true;
            }
        }
        return false;
    }

    private function matchesDateRange(string $dateValue, string $from, string $to): bool
    {
        if ($from === '' && $to === '') {
            return true;
        }

        if (trim($dateValue) === '') {
            return false;
        }

        try {
            $date = substr((string) $dateValue, 0, 10);
            if ($from !== '' && $date < substr($from, 0, 10)) {
                return false;
            }
            if ($to !== '' && $date > substr($to, 0, 10)) {
                return false;
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function queueSheetForPlatform(string $platform): string
    {
        return $platform === 'instagram' ? 'IG_Profile_URL_Queue' : 'TikTok_Profile_URL_Queue';
    }

    private function enrichedSheetForPlatform(string $platform): string
    {
        return $platform === 'instagram' ? 'Instagram_Profile_Enriched' : 'TikTok_Profile_Enriched';
    }

    private function parseDiscoveryId(string $id): array
    {
        $parts = explode(':', $id);
        if (count($parts) !== 2 || !in_array($parts[0], ['instagram', 'tiktok'], true) || !is_numeric($parts[1])) {
            throw new RuntimeException('Invalid discovery row id');
        }
        return [$parts[0], (int) $parts[1]];
    }

    private function parseQueueId(string $id, string $platform): int
    {
        $prefix = $platform . ':queue:';
        if (str_starts_with($id, $prefix)) {
            $value = substr($id, strlen($prefix));
            if (is_numeric($value)) {
                return (int) $value;
            }
        }
        if (is_numeric($id)) {
            return (int) $id;
        }
        throw new RuntimeException('Invalid queue row id');
    }

    private function parseRowNumber(string $id, string $prefix): int
    {
        $expectedPrefix = $prefix . ':';
        if (str_starts_with($id, $expectedPrefix)) {
            $value = substr($id, strlen($expectedPrefix));
            if (is_numeric($value)) {
                return (int) $value;
            }
        }
        if (is_numeric($id)) {
            return (int) $id;
        }
        throw new RuntimeException('Invalid row id');
    }

    private function queueId(string $platform, int $rowNumber): string
    {
        return $platform . ':queue:' . $rowNumber;
    }

    private function sanitizeMetric(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $value = (int) $value;
        return $value < 0 ? null : $value;
    }

    private function sanitizeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $value = (float) $value;
        return $value < 0 ? null : $value;
    }

    private function normalizeHandle(string $handle): string
    {
        $handle = trim($handle);
        if ($handle === '') {
            return '';
        }
        return str_starts_with($handle, '@') ? $handle : '@' . $handle;
    }

    private function instagramProfileUrl(string $username): string
    {
        $username = trim($username);
        return $username === '' ? '' : 'https://www.instagram.com/' . ltrim($username, '@') . '/';
    }

    private function tiktokProfileUrl(string $username): string
    {
        $username = trim($username);
        return $username === '' ? '' : 'https://www.tiktok.com/@' . ltrim($username, '@');
    }

    private function extractTaggedValue(string $text, string $key): ?string
    {
        if (preg_match('/(?:^|[;|\s])' . preg_quote($key, '/') . '=([^;|]+)/', $text, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}
