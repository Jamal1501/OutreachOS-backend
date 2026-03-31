<?php

namespace App\Http\Controllers;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\Task;
use App\Models\OutreachEvent;
use App\Models\DiscoveryItem;
use App\Models\MessageTemplate;
use App\Services\CreatorLifecycleService;
use App\Services\CreatorMergeService;
use App\Services\GoogleSheetsService;
use App\Services\InfluencerScoringService;
use App\Services\OperatorViewService;
use App\Services\OutreachLogService;
use App\Services\OperationalMirrorService;
use App\Services\ProjectResolverService;
use App\Services\TaskQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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
        private CreatorLifecycleService $lifecycle,
        private OperatorViewService $operatorView,
        private OutreachLogService $outreachLog,
        private OperationalMirrorService $mirror,
        private ProjectResolverService $projects,
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

        $dbItems = $this->loadDiscoveryItemsFromDatabase($sheetId, [
            'platforms' => $platforms,
            'search' => $search,
            'limit' => $limit,
            'offset' => $offset,
            'dedupe' => $dedupe,
        ]);

        if ($dbItems !== null) {
            return response()->json([
                'message' => 'Discovery rows fetched',
                'items' => $dbItems['items'],
                'total' => $dbItems['total'],
                'raw_total' => $dbItems['raw_total'],
                'deduped' => $dbItems['deduped'],
                'duplicate_groups' => $dbItems['duplicate_groups'],
                'source' => 'database',
            ]);
        }

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
            'source' => 'google_sheets',
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

        $dbItems = $this->loadCreatorsFromDatabase($sheetId, [
            'search' => $search,
            'platform' => $platform,
            'status' => $status,
            'niche' => $niche,
            'added_from' => $addedFrom,
            'added_to' => $addedTo,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if ($dbItems !== null) {
            return response()->json([
                'message' => 'Creators fetched',
                'items' => $dbItems['items'],
                'total' => $dbItems['total'],
            ]);
        }

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
        $payload = $validated['creator'];
        $dbProfile = $this->resolveCreatorProfileForRoute($sheetId, $id);

        if ($dbProfile) {
            $dbProfile->loadMissing('creator');
            $creator = $dbProfile->creator;
            if (!$creator) {
                return response()->json(['error' => 'Creator not found'], 404);
            }

            if (array_key_exists('niche', $payload)) {
                $creator->niche_category = trim((string) $payload['niche']) ?: null;
            }
            if (array_key_exists('notes', $payload)) {
                $creator->notes = (string) $payload['notes'];
            }
            if (array_key_exists('email', $payload)) {
                $creator->primary_email = trim((string) $payload['email']) ?: null;
            }
            if (array_key_exists('fullName', $payload)) {
                $creator->display_name = trim((string) $payload['fullName']) ?: null;
            }
            if (array_key_exists('status', $payload)) {
                $incomingStatus = trim((string) $payload['status']);
                $normalizedStatus = $this->lifecycle->isValidState($incomingStatus)
                    ? $incomingStatus
                    : $this->lifecycle->normalizeState($incomingStatus, 'enriched');
                $dbProfile->lifecycle_state = $normalizedStatus;
                $dbProfile->status = $this->lifecycle->sheetStatusForState($normalizedStatus);
            }

            $sheetRecord = $this->sheetRecordFromProfile($dbProfile);
            $score = $this->scoring->score($sheetRecord);
            $dbProfile->value_score = (int) round($score);
            $dbProfile->value_bar = $this->scoring->bar($score);
            $dbProfile->preferred_channel = filled($creator->primary_email) ? 'Email' : 'DM';
            $dbProfile->last_synced_at = now();

            $creator->save();
            $dbProfile->save();

            $freshProfile = $dbProfile->fresh('creator');
            $sheetSync = $this->syncCreatorProfileToSheet($sheetId, $freshProfile);

            return response()->json([
                'message' => 'Creator updated',
                'item' => $this->buildCreatorListItemFromProfile($freshProfile),
                'source' => 'database',
                'sheetSync' => $sheetSync,
            ]);
        }

        $rowNumber = $this->parseRowNumber($id, 'crm');
        $rows = $this->sheets->getRows($sheetId, 'Creators_CRM');
        $target = collect($rows)->first(fn (array $row) => (int) ($row['_row_number'] ?? 0) === $rowNumber);

        if (!$target) {
            return response()->json(['error' => 'Creator not found'], 404);
        }

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
        $this->mirror->syncCreators($sheetId, [$rowNumber]);

        return response()->json([
            'message' => 'Creator updated',
            'item' => $this->normalizeCreatorRow($target),
            'source' => 'google_sheets',
        ]);
    }


    public function linkProfiles(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'creatorIds' => ['required', 'array', 'min:2'],
            'creatorIds.*' => ['string'],
            'primaryCreatorId' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $project = $this->projects->findByWorkbookId($sheetId);

        if ($project) {
            $profiles = collect($validated['creatorIds'])
                ->map(fn (string $creatorId) => $this->resolveCreatorProfileForRoute($sheetId, $creatorId))
                ->filter()
                ->values();

            if ($profiles->count() >= 2) {
                $primaryProfile = $validated['primaryCreatorId']
                    ? $this->resolveCreatorProfileForRoute($sheetId, (string) $validated['primaryCreatorId'])
                    : null;
                if (!$primaryProfile || !$profiles->contains(fn (CreatorProfile $profile) => $profile->id === $primaryProfile->id)) {
                    $primaryProfile = $profiles->first();
                }

                $primaryCreator = $primaryProfile?->creator;
                if (!$primaryCreator) {
                    throw new RuntimeException('Primary creator not found');
                }

                $identityId = trim((string) ($primaryCreator->external_identity_key ?: ''));
                if ($identityId === '') {
                    $identityId = 'creator_' . Str::lower(Str::random(10));
                    $primaryCreator->external_identity_key = $identityId;
                }

                foreach ($profiles as $profile) {
                    $creator = $profile->creator;
                    if ($creator && $creator->id !== $primaryCreator->id) {
                        $primaryCreator->display_name = $primaryCreator->display_name ?: $creator->display_name;
                        $primaryCreator->primary_email = $primaryCreator->primary_email ?: $creator->primary_email;
                        $primaryCreator->country = $primaryCreator->country ?: $creator->country;
                        $primaryCreator->city = $primaryCreator->city ?: $creator->city;
                        $primaryCreator->primary_language = $primaryCreator->primary_language ?: $creator->primary_language;
                        $primaryCreator->niche_category = $primaryCreator->niche_category ?: $creator->niche_category;
                        $primaryCreator->notes = $this->mergeCreatorNotes((string) $primaryCreator->notes, (string) $creator->notes);
                    }
                }
                $primaryCreator->save();

                foreach ($profiles as $profile) {
                    $profile->creator_id = $primaryCreator->id;
                    $meta = is_array($profile->source_metadata) ? $profile->source_metadata : [];
                    $meta['creator_identity_id'] = $identityId;
                    $profile->source_metadata = $meta;
                    $profile->save();
                }

                Creator::query()
                    ->where('project_id', $project->id)
                    ->where('id', '!=', $primaryCreator->id)
                    ->whereDoesntHave('profiles')
                    ->delete();

                $linkedLabels = $profiles
                    ->map(fn (CreatorProfile $profile) => strtolower((string) $profile->platform) . ':' . (string) $profile->handle)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $sheetSync = [];
                foreach ($profiles as $profile) {
                    $sheetSync[] = $this->syncLinkedProfileMetadataToSheet($sheetId, $profile->fresh('creator'), $identityId, $linkedLabels, $primaryProfile && $profile->id === $primaryProfile->id);
                }

                $linked = $profiles->map(function (CreatorProfile $profile) {
                    $rowNumber = $this->extractSourceRowNumberFromProfile($profile);
                    $id = $rowNumber > 0 ? 'crm:' . $rowNumber : 'crmdb:' . $profile->id;
                    return [
                        'id' => $id,
                        'handle' => (string) ($profile->handle ?? ''),
                        'platform' => Str::lower((string) ($profile->platform ?? 'instagram')),
                    ];
                })->values()->all();

                return response()->json([
                    'message' => 'Creator profiles linked under one identity',
                    'data' => [
                        'creatorIdentityId' => $identityId,
                        'linked' => $linked,
                        'primaryCreatorId' => $this->extractSourceRowNumberFromProfile($primaryProfile) > 0 ? 'crm:' . $this->extractSourceRowNumberFromProfile($primaryProfile) : 'crmdb:' . $primaryProfile->id,
                    ],
                    'source' => 'database',
                    'sheetSync' => $sheetSync,
                ]);
            }
        }

        $rows = $this->sheets->getRows($sheetId, 'Creators_CRM');
        $rowNumbers = array_map(fn (string $creatorId) => $this->parseRowNumber($creatorId, 'crm'), $validated['creatorIds']);
        $primaryRowNumber = !empty($validated['primaryCreatorId']) ? $this->parseRowNumber((string) $validated['primaryCreatorId'], 'crm') : $rowNumbers[0];

        $selected = array_values(array_filter($rows, fn (array $row) => in_array((int) ($row['_row_number'] ?? 0), $rowNumbers, true)));
        if (count($selected) < 2) {
            throw new RuntimeException('Need at least two creator rows to link profiles');
        }

        $existingIdentityId = null;
        foreach ($selected as $row) {
            $existingIdentityId = $this->extractCreatorIdentityId($row);
            if ($existingIdentityId) break;
        }
        $identityId = $existingIdentityId ?: 'creator_' . Str::lower(Str::random(10));

        $linkedLabels = [];
        foreach ($selected as $row) {
            $linkedLabels[] = Str::lower((string) ($row['Platform'] ?? 'instagram')) . ':' . (string) ($row['Handle'] ?? '');
        }
        sort($linkedLabels);
        $linkedValue = implode(',', array_unique($linkedLabels));

        $updates = [];
        $linked = [];
        foreach ($selected as $row) {
            $rowNumber = (int) ($row['_row_number'] ?? 0);
            $notes = (string) ($row['Notes'] ?? '');
            $notes = $this->upsertTaggedValue($notes, 'creator_identity_id', $identityId);
            $notes = $this->upsertTaggedValue($notes, 'linked_profiles', $linkedValue);
            if ($rowNumber === $primaryRowNumber) {
                $notes = $this->upsertTaggedValue($notes, 'identity_primary', '1');
            }
            $row['Notes'] = $notes;
            $updates[] = ['rowNumber' => $rowNumber, 'record' => $row];
            $linked[] = [
                'id' => 'crm:' . $rowNumber,
                'handle' => (string) ($row['Handle'] ?? ''),
                'platform' => Str::lower((string) ($row['Platform'] ?? 'instagram')),
            ];
        }

        $this->sheets->batchUpdateAssocRows($sheetId, 'Creators_CRM', $updates);
        $this->mirror->syncCreators($sheetId, array_map(fn (array $item) => (int) str_replace('crm:', '', (string) $item['id']), $linked));

        return response()->json([
            'message' => 'Creator profiles linked under one identity',
            'data' => [
                'creatorIdentityId' => $identityId,
                'linked' => $linked,
                'primaryCreatorId' => 'crm:' . $primaryRowNumber,
            ],
            'source' => 'google_sheets',
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
    $project = $this->projects->findByWorkbookId($sheetId);

    if ($project) {
        // DB path — resolve profile IDs from queueIds
$rawQueueIds = array_values(array_unique($validated['queueIds']));

$profileIds = [];
$handles = [];
$profileUrls = [];

foreach ($rawQueueIds as $id) {
    if (str_starts_with($id, 'profiledb:')) {
        $profileIds[] = substr($id, 10);
        continue;
    }

    if (str_contains($id, ':source-url:')) {
        $parts = explode(':source-url:', $id, 2);
        $encodedUrl = $parts[1] ?? '';
        $decodedUrl = urldecode($encodedUrl);

        if ($decodedUrl !== '') {
            $profileUrls[] = rtrim($decodedUrl, '/');
            $profileUrls[] = rtrim($decodedUrl, '/') . '/';
        }

        continue;
    }

    if (str_starts_with($id, '@')) {
        $handles[] = $id;
        $handles[] = ltrim($id, '@');
        continue;
    }

    if (str_starts_with($id, 'http://') || str_starts_with($id, 'https://')) {
        $profileUrls[] = rtrim($id, '/');
        $profileUrls[] = rtrim($id, '/') . '/';
        continue;
    }

    $handles[] = $id;
    $handles[] = '@' . ltrim($id, '@');
}

$profileIds = array_values(array_unique(array_filter($profileIds)));
$handles = array_values(array_unique(array_filter($handles)));
$profileUrls = array_values(array_unique(array_filter($profileUrls)));

$profiles = CreatorProfile::query()
    ->with('creator')
    ->where('project_id', $project->id)
    ->where('platform', $platform)
    ->where(function ($q) use ($profileIds, $handles, $profileUrls) {
        $hasAny = false;

        if ($profileIds !== []) {
            $q->whereIn('id', $profileIds);
            $hasAny = true;
        }

        if ($handles !== []) {
            $method = $hasAny ? 'orWhereIn' : 'whereIn';
            $q->{$method}('handle', $handles);
            $hasAny = true;
        }

        if ($profileUrls !== []) {
            $method = $hasAny ? 'orWhereIn' : 'whereIn';
            $q->{$method}('profile_url', $profileUrls);
        }
    })
    ->get();

        $created = 0;
        $updated = 0;
        $affectedProfileIds = [];

        foreach ($profiles as $profile) {
            $existingBefore = $profile->exists;
            $profile->status = $profile->status && !in_array(strtoupper((string)$profile->status), ['NEW', 'DISCOVERED', 'ENRICHED'], true)
                ? $profile->status : 'NEW';
            $profile->lifecycle_state = 'new';
            $profile->last_synced_at = now();
            $profile->save();
            $affectedProfileIds[] = $profile->id;
            $existingBefore ? $updated++ : $created++;
        }

        $result = [
            'sourceSheet' => 'database',
            'processed' => $profiles->count(),
            'created' => $created,
            'updated' => $updated,
            'skipped' => count($validated['queueIds']) - $profiles->count(),
            'affectedProfileIds' => $affectedProfileIds,
            'affectedRowNumbers' => [],
            'selectedQueueCount' => count($validated['queueIds']),
            'selectionMode' => 'database',
            'resolvedBy' => ['database'],
        ];

        if (($validated['createTasks'] ?? false) === true && $affectedProfileIds !== []) {
            try {
                $result['taskGeneration'] = $this->taskQueue->generateInitialTasks($sheetId, [
                    'limit' => $validated['taskLimit'] ?? 50,
                    'profileIds' => $affectedProfileIds,
                ]);
            } catch (\Throwable $e) {
                report($e);
                $result['taskGenerationError'] = $e->getMessage();
            }
        }

        return response()->json([
            'message' => 'Selected queue rows merged into Creators_CRM',
            'result' => $result,
            'source' => 'database',
        ]);
    }

    // Legacy Google Sheets fallback
    $queueSheet = $this->queueSheetForPlatform($platform);
    $sourceSheet = $this->enrichedSheetForPlatform($platform);

    $queueRows = $this->sheets->getRows($sheetId, $queueSheet);
    $sourceRows = $this->sheets->getRows($sheetId, $sourceSheet);

    $selection = $this->resolveSelectedMergeTargets($platform, $validated['queueIds'], $queueRows, $sourceRows);
    $selectedQueueRowNumbers = $selection['selectedQueueRowNumbers'];
    $selectedQueueRows = $selection['selectedQueueRows'];
    $sourceRowNumbers = $selection['sourceRowNumbers'];

    $result = $this->creatorMerge->mergeSelectedFromEnrichedSheet($sheetId, $sourceSheet, $sourceRowNumbers);
    $result['selectedQueueCount'] = count($selectedQueueRows);
    $result['selectedQueueRowNumbers'] = $selectedQueueRowNumbers;
    $result['matchedSourceRowNumbers'] = $sourceRowNumbers;
    $result['selectionMode'] = $selection['selectionMode'];
    $result['resolvedBy'] = $selection['resolvedBy'];

    $affectedRowNumbers = array_values(array_unique(array_filter(
        array_map('intval', (array) ($result['affectedRowNumbers'] ?? [])),
        fn (int $rowNumber) => $rowNumber > 1
    )));
    $affectedProfileIds = array_values(array_unique(array_filter(
        array_map('strval', (array) ($result['affectedProfileIds'] ?? [])),
        fn (string $profileId) => trim($profileId) !== ''
    )));

    if ($affectedProfileIds === [] && $affectedRowNumbers !== []) {
        $this->mirror->syncCreators($sheetId, $affectedRowNumbers);
    }

    if (($validated['createTasks'] ?? false) === true) {
        if ($affectedProfileIds === [] && $affectedRowNumbers === []) {
            $result['taskGeneration'] = [
                'created' => 0,
                'taskSheet' => 'Task_Queue',
                'sourceRowNumbers' => [],
                'sourceProfileIds' => [],
            ];
        } else {
            try {
                $taskOptions = ['limit' => $validated['taskLimit'] ?? 50];
                if ($affectedProfileIds !== []) {
                    $taskOptions['profileIds'] = $affectedProfileIds;
                } else {
                    $taskOptions['rowNumbers'] = $affectedRowNumbers;
                }
                $result['taskGeneration'] = $this->taskQueue->generateInitialTasks($sheetId, $taskOptions);
            } catch (\Throwable $e) {
                report($e);
                $result['taskGenerationError'] = $e->getMessage();
            }
        }
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

        $dbItems = $this->loadMessageTemplatesFromDatabase($sheetId, [
            'platform' => $platform,
            'stage' => $stage,
            'niche' => $niche,
        ]);

        if ($dbItems !== null) {
            return response()->json([
                'message' => 'Message templates fetched',
                'items' => $dbItems,
            ]);
        }

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
        $project = $this->projects->resolveByWorkbookId($sheetId);
        $templatePayload = $validated['template'];

        $template = MessageTemplate::query()->create([
            'project_id' => $project->id,
            'angle_id' => (string) ($templatePayload['angleId'] ?? ''),
            'platform' => (string) ($templatePayload['platform'] ?? 'instagram'),
            'niche' => (string) ($templatePayload['niche'] ?? ''),
            'stage' => (string) ($templatePayload['stage'] ?? 'cold_invite'),
            'copy' => (string) ($templatePayload['copy'] ?? ''),
            'notes' => (string) ($templatePayload['notes'] ?? ''),
            'psychological_trigger' => (string) ($templatePayload['psychologicalTrigger'] ?? $templatePayload['trigger'] ?? ''),
            'metadata' => [],
        ]);

        $sheetSync = $this->syncMessageTemplateToSheet($sheetId, $template->fresh());
        $fresh = $template->fresh();
        $rowNumber = (int) (($fresh->metadata['source_row_number'] ?? 0));
        $id = $rowNumber > 1 ? 'msg:' . $rowNumber : 'msgdb:' . $fresh->id;

        return response()->json([
            'message' => 'Message template created',
            'id' => $id,
            'source' => 'database',
            'sheetSync' => $sheetSync,
        ]);
    }

    public function updateMessage(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'template' => ['required', 'array'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $template = $this->resolveMessageTemplateForRoute($sheetId, $id);
        if (!$template) {
            throw new RuntimeException('Message template not found');
        }

        $payload = $validated['template'];
        $template->fill([
            'angle_id' => (string) ($payload['angleId'] ?? ''),
            'platform' => (string) ($payload['platform'] ?? 'instagram'),
            'niche' => (string) ($payload['niche'] ?? ''),
            'stage' => (string) ($payload['stage'] ?? 'cold_invite'),
            'copy' => (string) ($payload['copy'] ?? ''),
            'notes' => (string) ($payload['notes'] ?? ''),
            'psychological_trigger' => (string) ($payload['psychologicalTrigger'] ?? $payload['trigger'] ?? ''),
        ]);
        $template->save();

        $sheetSync = $this->syncMessageTemplateToSheet($sheetId, $template->fresh());

        return response()->json([
            'message' => 'Message template updated',
            'source' => 'database',
            'sheetSync' => $sheetSync,
        ]);
    }

    public function deleteMessage(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $template = $this->resolveMessageTemplateForRoute($sheetId, $id);

        if (!$template) {
            throw new RuntimeException('Message template not found');
        }

        $metadata = is_array($template->metadata) ? $template->metadata : [];
        $rowNumber = (int) ($metadata['source_row_number'] ?? 0);
        $templateId = $template->id;
        $template->delete();

        $sheetSync = ['mode' => 'not_attempted', 'rowNumber' => $rowNumber];
        if ($rowNumber > 1) {
            try {
                $this->sheets->clearAssocRow($sheetId, 'Message_Library', $rowNumber);
                $sheetSync = ['mode' => 'cleared', 'rowNumber' => $rowNumber];
            } catch (\Throwable $e) {
                Log::warning('Message_Library sheet sync failed after database template delete', [
                    'sheet_id' => $sheetId,
                    'row_number' => $rowNumber,
                    'template_id' => $templateId,
                    'error' => $e->getMessage(),
                ]);
                $sheetSync = ['mode' => 'failed', 'rowNumber' => $rowNumber, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Message template deleted',
            'source' => 'database',
            'sheetSync' => $sheetSync,
        ]);
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

    $project = $this->projects->findByWorkbookId($sheetId);

    if ($project) {
        $items = [];
        $query = CreatorProfile::query()
            ->with('creator')
            ->where('project_id', $project->id)
            ->whereIn('platform', $platforms);

        foreach ($query->get() as $profile) {
            $profileUrl = (string) ($profile->profile_url ?: $profile->dm_link ?: '');
            $handle = (string) ($profile->handle ?: '');
            $status = strtolower((string) ($profile->lifecycle_state ?: $profile->status ?: 'queued'));
            $enriched = in_array($status, ['new', 'enriched', 'contacted', 'follow_request_sent',
                'followed_up', 'replied', 'accepted', 'declined', 'archived'], true);

            $items[] = [
                'id' => 'profiledb:' . $profile->id,
                'rowId' => 'profiledb:' . $profile->id,
                'platform' => (string) $profile->platform,
                'handle' => $handle,
                'profileUrl' => $profileUrl,
                'status' => $enriched ? 'enriched' : $status,
                'sourceNotes' => (string) ($profile->source_reference ?: ''),
                'addedAt' => optional($profile->created_at)->toDateTimeString() ?? '',
                'readyToMerge' => $enriched,
                'enrichedRowNumber' => null,
                'sourceSheet' => 'database',
            ];
        }

        usort($items, fn (array $a, array $b) => strcmp((string) ($b['addedAt'] ?? ''), (string) ($a['addedAt'] ?? '')));

        return response()->json([
            'message' => 'Enrichment queue fetched',
            'items' => $items,
            'source' => 'database',
        ]);
    }

    // Legacy Google Sheets fallback
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
        'source' => 'google_sheets',
    ]);
}

public function operatorView(Request $request)
{
    try {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);

        return response()->json([
            'message' => 'Operator view fetched',
            'data' => $this->operatorView->build($sheetId),
        ]);
    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'message' => 'Failed to build operator view',
            'error' => $e->getMessage(),
            'exception' => class_basename($e),
        ], 500);
    }
}

    public function creatorDecisionSheet(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $rowNumber = $this->parseRowNumber($id, 'crm');

        return response()->json([
            'message' => 'Creator decision sheet fetched',
            'data' => $this->operatorView->buildDecisionSheet($sheetId, $rowNumber),
        ]);
    }

    public function transitionCreator(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'toState' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'actor' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $toState = trim((string) $validated['toState']);
        $dbProfile = $this->resolveCreatorProfileForRoute($sheetId, $id);

        if ($dbProfile) {
            $dbProfile->loadMissing('creator');
            $currentState = $this->lifecycle->normalizeState((string) ($dbProfile->lifecycle_state ?: $dbProfile->status ?: ''), 'enriched');

            if (!$this->lifecycle->isValidState($toState)) {
                throw new RuntimeException('Invalid target state');
            }

            if (!$this->lifecycle->canTransition($currentState, $toState)) {
                throw new RuntimeException(sprintf('Invalid transition %s -> %s', $currentState, $toState));
            }

            $sheetRecord = $this->sheetRecordFromProfile($dbProfile);
            $updatedRecord = $this->lifecycle->applyTransition($sheetRecord, $currentState, $toState, $validated);
            $this->applySheetRecordToProfile($dbProfile, $updatedRecord);
            if ($dbProfile->creator) {
                $dbProfile->creator->save();
            }
            $dbProfile->save();

            $freshProfile = $dbProfile->fresh('creator');
            $sheetSync = $this->syncCreatorProfileToSheet($sheetId, $freshProfile);

            $eventId = $this->outreachLog->appendEvent($sheetId, [
                'creator_profile_id' => $freshProfile->id,
                'Platform' => (string) ($freshProfile->platform ?? ''),
                'Handle' => (string) ($freshProfile->handle ?? ''),
                'Channel' => (string) ($freshProfile->preferred_channel ?? ''),
                'Event_Type' => $this->lifecycle->eventTypeForTransition($currentState, $toState),
                'Status' => strtoupper($this->lifecycle->sheetStatusForState($toState)),
                'URL' => (string) ($freshProfile->dm_link ?: $freshProfile->profile_url ?: ''),
                'Notes' => trim(sprintf(
                    'transition %s -> %s%s%s',
                    $currentState,
                    $toState,
                    !empty($validated['reason']) ? '; reason=' . $validated['reason'] : '',
                    !empty($validated['notes']) ? '; note=' . str_replace(';', ',', (string) $validated['notes']) : ''
                )),
            ]);

            $rowNumber = $this->extractSourceRowNumberFromProfile($freshProfile);
            return response()->json([
                'message' => 'Creator transitioned',
                'data' => [
                    'creatorId' => $rowNumber > 0 ? 'crm:' . $rowNumber : 'crmdb:' . $freshProfile->id,
                    'fromState' => $currentState,
                    'toState' => $toState,
                    'eventId' => $eventId,
                ],
                'source' => 'database',
                'sheetSync' => $sheetSync,
            ]);
        }

        $rowNumber = $this->parseRowNumber($id, 'crm');
        $creatorRow = collect($this->sheets->getRows($sheetId, 'Creators_CRM'))
            ->first(fn (array $row) => (int) ($row['_row_number'] ?? 0) === $rowNumber);

        if (!$creatorRow) {
            throw new RuntimeException('Creator not found');
        }

        $enrichmentStatus = $this->normalizeEnrichmentStatus($creatorRow);
        $fromState = $this->lifecycle->normalizeState((string) ($creatorRow['Status'] ?? ''), $enrichmentStatus);

        if (!$this->lifecycle->isValidState($toState)) {
            throw new RuntimeException('Invalid target state');
        }

        if (!$this->lifecycle->canTransition($fromState, $toState)) {
            throw new RuntimeException(sprintf('Invalid transition %s -> %s', $fromState, $toState));
        }

        $updated = $this->lifecycle->applyTransition($creatorRow, $fromState, $toState, $validated);
        $this->sheets->updateAssocRow($sheetId, 'Creators_CRM', $rowNumber, $updated);
        $this->mirror->syncCreators($sheetId, [$rowNumber]);

        $eventId = $this->outreachLog->appendEvent($sheetId, [
            'Platform' => (string) ($creatorRow['Platform'] ?? ''),
            'Handle' => (string) ($creatorRow['Handle'] ?? ''),
            'Channel' => (string) ($creatorRow['Preferred_Channel'] ?? ''),
            'Event_Type' => $this->lifecycle->eventTypeForTransition($fromState, $toState),
            'Status' => strtoupper($this->lifecycle->sheetStatusForState($toState)),
            'URL' => (string) ($creatorRow['DM_Link'] ?? ''),
            'Notes' => trim(sprintf(
                'transition %s -> %s%s%s',
                $fromState,
                $toState,
                !empty($validated['reason']) ? '; reason=' . $validated['reason'] : '',
                !empty($validated['notes']) ? '; note=' . str_replace(';', ',', (string) $validated['notes']) : ''
            )),
        ]);

        return response()->json([
            'message' => 'Creator transitioned',
            'data' => [
                'creatorId' => 'crm:' . $rowNumber,
                'fromState' => $fromState,
                'toState' => $toState,
                'eventId' => $eventId,
            ],
            'source' => 'google_sheets',
        ]);
    }


    public function dashboardMetrics(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
        ]);

        $sheetId = $this->resolveSheetId($validated['sheetId'] ?? null);
        $project = $this->projects->findByWorkbookId($sheetId);

        if ($project) {
            $profiles = CreatorProfile::query()->where('project_id', $project->id)->get();
            $tasks = Task::query()->where('project_id', $project->id)->get();
            $outreachEvents = OutreachEvent::query()->where('project_id', $project->id)->get();
            $discoveredCount = DiscoveryItem::query()
    ->where('project_id', $project->id)
    ->selectRaw("
        COUNT(DISTINCT COALESCE(
            NULLIF(duplicate_key, ''),
            NULLIF(handle, ''),
            NULLIF(username, ''),
            NULLIF(post_url, ''),
            id::text
        )) as aggregate
    ")
    ->value('aggregate') ?? 0;

            $today = now()->toDateString();
            $normalizedStates = $profiles->map(function (CreatorProfile $profile) {
                $rawState = (string) ($profile->lifecycle_state ?: $profile->status ?: '');
                return $this->lifecycle->normalizeState($rawState, 'enriched');
            });

            $readyForOutreach = $profiles->filter(function (CreatorProfile $profile) {
                $state = $this->lifecycle->normalizeState((string) ($profile->lifecycle_state ?: $profile->status ?: ''), 'enriched');
                return in_array($state, ['approved_for_outreach', 'queued'], true)
                    || ($state === 'enriched' && (float) ($profile->value_score ?? 0) >= 55);
            })->count();

            $metrics = [
                'creatorsDiscovered' => $discoveredCount,
                'creatorsEnriched' => $profiles->count(),
                'readyForOutreach' => $readyForOutreach,
                'tasksDueToday' => $tasks->filter(function (Task $task) use ($today) {
                    $status = strtoupper(trim((string) ($task->status ?: 'PENDING')));
                    $dueDate = optional($task->due_at)?->toDateString() ?? '';
                    return $dueDate === $today && !in_array($status, ['DONE', 'COMPLETED', 'SKIPPED'], true);
                })->count(),
                'outreachSent' => $outreachEvents->filter(fn (OutreachEvent $event) => Str::contains(Str::upper((string) $event->event_type), ['SENT', 'OUTREACH']))->count(),
                'repliesReceived' => $outreachEvents->filter(fn (OutreachEvent $event) => Str::contains(Str::upper((string) $event->event_type), ['REPLY', 'ACCEPTED', 'DEAL_WON']))->count(),
                'scrapeSpend' => 0,
            ];

            return response()->json([
                'message' => 'Dashboard metrics fetched',
                'metrics' => $metrics,
            ]);
        }

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

    private function loadCreatorsFromDatabase(string $sheetId, array $filters): ?array
    {
        if (!$this->mirror->enabled()) {
            return null;
        }

        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return null;
        }

        $query = CreatorProfile::query()
            ->with(['creator:id,display_name,primary_email,country,city,primary_language,niche_category,notes'])
            ->where('project_id', $project->id);

        if (($filters['platform'] ?? '') !== '') {
            $query->where('platform', $filters['platform']);
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('lifecycle_state', $filters['status']);
        }

        if (($filters['niche'] ?? '') !== '') {
            $query->whereHas('creator', fn ($q) => $q->whereRaw("LOWER(COALESCE(niche_category, '')) = ?", [$filters['niche']]));
        }

        if (($filters['added_from'] ?? '') !== '') {
            $query->where('created_at', '>=', $filters['added_from'] . ' 00:00:00');
        }

        if (($filters['added_to'] ?? '') !== '') {
            $query->where('created_at', '<=', $filters['added_to'] . ' 23:59:59');
        }

        if (($filters['search'] ?? '') !== '') {
            $searchLike = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchLike) {
                $q->whereRaw('LOWER(handle) LIKE ?', [$searchLike])
                    ->orWhereRaw("LOWER(COALESCE(username, '')) LIKE ?", [$searchLike])
                    ->orWhereHas('creator', function ($creatorQuery) use ($searchLike) {
                        $creatorQuery->whereRaw("LOWER(COALESCE(display_name, '')) LIKE ?", [$searchLike])
                            ->orWhereRaw("LOWER(COALESCE(primary_email, '')) LIKE ?", [$searchLike])
                            ->orWhereRaw("LOWER(COALESCE(niche_category, '')) LIKE ?", [$searchLike]);
                    });
            });
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            return [
                'items' => [],
                'total' => 0,
            ];
        }

        $profiles = $query
            ->orderByDesc('created_at')
            ->offset((int) ($filters['offset'] ?? 0))
            ->limit((int) ($filters['limit'] ?? 200))
            ->get();

        $creatorIds = $profiles->pluck('creator_id')->filter()->unique()->values();
        $counts = CreatorProfile::query()
            ->selectRaw('creator_id, COUNT(*) as aggregate_count')
            ->whereIn('creator_id', $creatorIds)
            ->groupBy('creator_id')
            ->pluck('aggregate_count', 'creator_id');

        $items = $profiles->map(function (CreatorProfile $profile) use ($counts) {
            $creator = $profile->creator;
            $addedAt = optional($profile->created_at)?->toDateTimeString() ?? '';
            $score = (float) ($profile->value_score ?? 0);
            $status = trim((string) ($profile->lifecycle_state ?: $profile->status ?: 'discovered'));
            $rowNumber = (int) (($profile->source_metadata['sheet_row_number'] ?? 0));
            $id = $rowNumber > 1 ? 'crm:' . $rowNumber : 'crmdb:' . $profile->id;
            $enrichmentStatus = ($profile->followers_count !== null || $profile->engagement_rate_pct !== null || filled(optional($creator)->primary_email))
                ? 'enriched'
                : 'pending';

            return [
                'id' => $id,
                'rowId' => $id,
                'platform' => strtolower((string) ($profile->platform ?: 'instagram')),
                'handle' => (string) ($profile->handle ?: ''),
                'fullName' => (string) (optional($creator)->display_name ?: ''),
                'followers' => $profile->followers_count,
                'engagementRate' => $profile->engagement_rate_pct !== null ? (float) $profile->engagement_rate_pct : null,
                'email' => (string) (optional($creator)->primary_email ?: ''),
                'status' => $status,
                'enrichmentStatus' => $enrichmentStatus,
                'profileUrl' => (string) ($profile->profile_url ?: ''),
                'dmUrl' => (string) ($profile->dm_link ?: $profile->profile_url ?: ''),
                'niche' => (string) (optional($creator)->niche_category ?: ''),
                'lastContactDate' => optional($profile->dm_sent_at)?->toDateTimeString() ?? '',
                'notes' => (string) (optional($creator)->notes ?: ''),
                'addedAt' => $addedAt,
                'valueScore' => (int) round($score),
                'valueTier' => Str::lower($this->scoring->tier($score)),
                'preferredChannel' => (string) ($profile->preferred_channel ?: ''),
                'creatorIdentityId' => (string) ($creator?->id ?: ''),
                'linkedProfileCount' => (int) ($counts[$profile->creator_id] ?? 1),
            ];
        })->values()->all();

        return ['items' => $items, 'total' => $total];
    }

    private function loadMessageTemplatesFromDatabase(string $sheetId, array $filters): ?array
    {
        if (!$this->mirror->enabled()) {
            return null;
        }

        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return null;
        }

        $query = MessageTemplate::query()->where('project_id', $project->id);

        if (($filters['platform'] ?? '') !== '') {
            $query->whereRaw('LOWER(platform) = ?', [$filters['platform']]);
        }
        if (($filters['stage'] ?? '') !== '') {
            $query->whereRaw('LOWER(stage) = ?', [$filters['stage']]);
        }
        if (($filters['niche'] ?? '') !== '') {
            $query->whereRaw("LOWER(COALESCE(niche, '')) = ?", [$filters['niche']]);
        }

        $rows = $query->orderByDesc('created_at')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        return $rows->map(function (MessageTemplate $template) {
            $rowNumber = (int) (($template->metadata['source_row_number'] ?? 0));
            return [
                'id' => $rowNumber > 1 ? 'msg:' . $rowNumber : 'msgdb:' . $template->id,
                'angleId' => (string) $template->angle_id,
                'platform' => strtolower((string) ($template->platform ?: 'instagram')),
                'niche' => (string) ($template->niche ?: ''),
                'stage' => (string) ($template->stage ?: 'cold_invite'),
                'copy' => (string) ($template->copy ?: ''),
                'notes' => (string) ($template->notes ?: ''),
                'psychologicalTrigger' => (string) ($template->psychological_trigger ?: ''),
            ];
        })->values()->all();
    }


    private function resolveCreatorProfileForRoute(string $sheetId, string $id): ?CreatorProfile
    {
        if (!$this->mirror->enabled()) {
            return null;
        }

        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return null;
        }

        $id = trim($id);
        if ($id === '') {
            return null;
        }

        if (Str::startsWith($id, 'crm:')) {
            $rowNumber = (int) substr($id, 4);
            if ($rowNumber > 0) {
                return CreatorProfile::query()
                    ->with('creator')
                    ->where('project_id', $project->id)
                    ->where(function ($query) use ($rowNumber) {
                        $query->where('source_reference', 'Creators_CRM:' . $rowNumber)
                            ->orWhere('source_metadata->sheet_row_number', $rowNumber);
                    })
                    ->first();
            }
        }

        if (Str::startsWith($id, 'crmdb:')) {
            $candidate = substr($id, 6);
            return CreatorProfile::query()->with('creator')->where('project_id', $project->id)->where('id', $candidate)->first();
        }

        if (Str::startsWith($id, 'profile:')) {
            $candidate = substr($id, 8);
            return CreatorProfile::query()->with('creator')->where('project_id', $project->id)->where('id', $candidate)->first();
        }

        if ($this->isUuid($id)) {
            return CreatorProfile::query()->with('creator')->where('project_id', $project->id)->where('id', $id)->first();
        }

        return null;
    }

    private function buildCreatorListItemFromProfile(CreatorProfile $profile): array
    {
        $creator = $profile->creator;
        $rowNumber = $this->extractSourceRowNumberFromProfile($profile);
        $id = $rowNumber > 0 ? 'crm:' . $rowNumber : 'crmdb:' . $profile->id;
        $score = (float) ($profile->value_score ?? 0);
        $status = trim((string) ($profile->lifecycle_state ?: $profile->status ?: 'discovered'));
        $enrichmentStatus = ($profile->followers_count !== null || $profile->engagement_rate_pct !== null || filled(optional($creator)->primary_email))
            ? 'enriched'
            : 'pending';

        return [
            'id' => $id,
            'rowId' => $id,
            'platform' => strtolower((string) ($profile->platform ?: 'instagram')),
            'handle' => (string) ($profile->handle ?: ''),
            'fullName' => (string) (optional($creator)->display_name ?: ''),
            'followers' => $profile->followers_count,
            'engagementRate' => $profile->engagement_rate_pct !== null ? (float) $profile->engagement_rate_pct : null,
            'email' => (string) (optional($creator)->primary_email ?: ''),
            'status' => $status,
            'enrichmentStatus' => $enrichmentStatus,
            'profileUrl' => (string) ($profile->profile_url ?: ''),
            'dmUrl' => (string) ($profile->dm_link ?: $profile->profile_url ?: ''),
            'niche' => (string) (optional($creator)->niche_category ?: ''),
            'lastContactDate' => optional($profile->dm_sent_at)?->toDateTimeString() ?? '',
            'notes' => (string) (optional($creator)->notes ?: ''),
            'addedAt' => optional($profile->created_at)?->toDateTimeString() ?? '',
            'valueScore' => (int) round($score),
            'valueTier' => Str::lower($this->scoring->tier($score)),
            'preferredChannel' => (string) ($profile->preferred_channel ?: ''),
            'creatorIdentityId' => (string) (optional($creator)->external_identity_key ?: ''),
            'linkedProfileCount' => $creator ? $creator->profiles()->count() : 1,
        ];
    }

    private function sheetRecordFromProfile(CreatorProfile $profile): array
    {
        $creator = $profile->creator;
        $status = trim((string) ($profile->status ?: $this->lifecycle->sheetStatusForState((string) ($profile->lifecycle_state ?: 'discovered'))));
        $metadata = is_array($profile->source_metadata) ? $profile->source_metadata : [];

        return [
            'Platform' => strtolower((string) ($profile->platform ?: 'instagram')),
            'Handle' => (string) ($profile->handle ?: ''),
            'Name' => (string) ($creator?->display_name ?: ''),
            'DM_Link' => (string) ($profile->dm_link ?: $profile->profile_url ?: ''),
            'Followers' => $profile->followers_count !== null ? (string) $profile->followers_count : '',
            'Engagement_Rate_%' => $profile->engagement_rate_pct !== null ? (string) $profile->engagement_rate_pct : '',
            'Contact_Email' => (string) ($creator?->primary_email ?: ''),
            'Niche_Category' => (string) ($creator?->niche_category ?: ''),
            'Status' => $status,
            'Value_Score' => $profile->value_score !== null ? (string) $profile->value_score : '',
            'Value_Bar' => (string) ($profile->value_bar ?: ''),
            'Preferred_Channel' => (string) ($profile->preferred_channel ?: ''),
            'Last_Content_Date' => optional($profile->last_content_at)?->toDateTimeString() ?? '',
            'Duplicate_Flag' => (string) ($profile->duplicate_flag ?: ''),
            'Accepted_(Y/N)' => $profile->accepted_flag ? 'Y' : 'N',
            'Follow_Up_Needed_(Y/N)' => $profile->follow_up_needed ? 'Y' : 'N',
            'DM_Sent_Date' => optional($profile->dm_sent_at)?->toDateString() ?? '',
            'Response_Date' => optional($profile->responded_at)?->toDateString() ?? '',
            'Notes' => (string) ($creator?->notes ?: ''),
            'Angle_Assigned' => (string) ($metadata['angle_assigned'] ?? ''),
            'Commission_Model' => (string) ($metadata['commission_model'] ?? ''),
            'Reaction_Video_Link' => (string) ($metadata['reaction_video_link'] ?? ''),
        ];
    }

    private function applySheetRecordToProfile(CreatorProfile $profile, array $record): void
    {
        $creator = $profile->creator;
        if (!$creator) {
            return;
        }

        $creator->display_name = trim((string) ($record['Name'] ?? '')) ?: null;
        $creator->primary_email = trim((string) ($record['Contact_Email'] ?? '')) ?: null;
        $creator->niche_category = trim((string) ($record['Niche_Category'] ?? '')) ?: null;
        $creator->notes = (string) ($record['Notes'] ?? '');

        $profile->profile_url = trim((string) ($record['DM_Link'] ?? '')) ?: $profile->profile_url;
        $profile->dm_link = trim((string) ($record['DM_Link'] ?? '')) ?: $profile->dm_link;
        $profile->status = trim((string) ($record['Status'] ?? $profile->status ?: 'DISCOVERED'));
        $profile->lifecycle_state = $this->lifecycle->normalizeState((string) ($record['Status'] ?? ''), 'enriched');
        $profile->preferred_channel = trim((string) ($record['Preferred_Channel'] ?? '')) ?: null;
        $profile->value_score = $this->sanitizeMetric($record['Value_Score'] ?? null);
        $profile->value_bar = trim((string) ($record['Value_Bar'] ?? '')) ?: null;
        $profile->followers_count = $this->sanitizeMetric($record['Followers'] ?? null);
        $profile->engagement_rate_pct = $this->sanitizeFloat($record['Engagement_Rate_%'] ?? null);
        $profile->last_content_at = trim((string) ($record['Last_Content_Date'] ?? '')) !== '' ? $record['Last_Content_Date'] : $profile->last_content_at;
        $profile->duplicate_flag = trim((string) ($record['Duplicate_Flag'] ?? '')) ?: null;
        $profile->accepted_flag = Str::upper(trim((string) ($record['Accepted_(Y/N)'] ?? 'N'))) === 'Y';
        $profile->follow_up_needed = Str::upper(trim((string) ($record['Follow_Up_Needed_(Y/N)'] ?? 'N'))) === 'Y';
        $profile->dm_sent_at = trim((string) ($record['DM_Sent_Date'] ?? '')) !== '' ? $record['DM_Sent_Date'] : $profile->dm_sent_at;
        $profile->responded_at = trim((string) ($record['Response_Date'] ?? '')) !== '' ? $record['Response_Date'] : $profile->responded_at;
        $profile->last_synced_at = now();
    }

    private function syncCreatorProfileToSheet(string $sheetId, CreatorProfile $profile): array
    {
        $rowNumber = $this->extractSourceRowNumberFromProfile($profile);
        if ($rowNumber <= 0) {
            return ['synced' => false, 'reason' => 'no_sheet_row'];
        }

        try {
            $sheetRow = collect($this->sheets->getRows($sheetId, 'Creators_CRM'))
                ->first(fn (array $row) => (int) ($row['_row_number'] ?? 0) === $rowNumber);
            if (!$sheetRow) {
                return ['synced' => false, 'reason' => 'sheet_row_missing'];
            }

            $record = $this->sheetRecordFromProfile($profile->loadMissing('creator'));
            foreach ($record as $key => $value) {
                $sheetRow[$key] = $value;
            }
            $this->sheets->updateAssocRow($sheetId, 'Creators_CRM', $rowNumber, $sheetRow);

            return ['synced' => true, 'rowNumber' => $rowNumber];
        } catch (\Throwable $e) {
            Log::warning('Creators_CRM sheet sync failed after database creator update', [
                'sheet_id' => $sheetId,
                'profile_id' => $profile->id,
                'row_number' => $rowNumber,
                'error' => $e->getMessage(),
            ]);

            return ['synced' => false, 'reason' => 'sheet_sync_failed', 'error' => $e->getMessage()];
        }
    }

    private function syncLinkedProfileMetadataToSheet(string $sheetId, CreatorProfile $profile, string $identityId, array $linkedLabels, bool $isPrimary): array
    {
        $rowNumber = $this->extractSourceRowNumberFromProfile($profile);
        if ($rowNumber <= 0) {
            return ['synced' => false, 'reason' => 'no_sheet_row'];
        }

        try {
            $sheetRow = collect($this->sheets->getRows($sheetId, 'Creators_CRM'))
                ->first(fn (array $row) => (int) ($row['_row_number'] ?? 0) === $rowNumber);
            if (!$sheetRow) {
                return ['synced' => false, 'reason' => 'sheet_row_missing'];
            }

            $notes = (string) ($sheetRow['Notes'] ?? '');
            $notes = $this->upsertTaggedValue($notes, 'creator_identity_id', $identityId);
            $notes = $this->upsertTaggedValue($notes, 'linked_profiles', implode(',', $linkedLabels));
            if ($isPrimary) {
                $notes = $this->upsertTaggedValue($notes, 'identity_primary', '1');
            }
            $sheetRow['Notes'] = $notes;
            $this->sheets->updateAssocRow($sheetId, 'Creators_CRM', $rowNumber, $sheetRow);

            return ['synced' => true, 'rowNumber' => $rowNumber];
        } catch (\Throwable $e) {
            Log::warning('Creators_CRM sheet sync failed after database link profiles', [
                'sheet_id' => $sheetId,
                'profile_id' => $profile->id,
                'row_number' => $rowNumber,
                'error' => $e->getMessage(),
            ]);

            return ['synced' => false, 'reason' => 'sheet_sync_failed', 'error' => $e->getMessage()];
        }
    }

    private function extractSourceRowNumberFromProfile(CreatorProfile $profile): int
    {
        $metaRow = (int) (($profile->source_metadata['sheet_row_number'] ?? 0) ?: 0);
        if ($metaRow > 0) {
            return $metaRow;
        }

        $sourceReference = (string) ($profile->source_reference ?? '');
        if (Str::startsWith($sourceReference, 'Creators_CRM:')) {
            return max(0, (int) substr($sourceReference, strlen('Creators_CRM:')));
        }

        return 0;
    }

    private function mergeCreatorNotes(string $primaryNotes, string $secondaryNotes): string
    {
        $primaryNotes = trim($primaryNotes);
        $secondaryNotes = trim($secondaryNotes);
        if ($secondaryNotes === '' || Str::contains($primaryNotes, $secondaryNotes)) {
            return $primaryNotes;
        }
        if ($primaryNotes === '') {
            return $secondaryNotes;
        }
        return trim($primaryNotes . ' | ' . $secondaryNotes, ' |');
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', trim($value));
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
        $dbProject = $this->projects->findByWorkbookId($sheetId);

        foreach ($postIds as $postId) {
            $queueRecord = null;
            $dbDiscoveryItem = null;

            if (Str::startsWith((string) $postId, 'discdb:') && $dbProject) {
                $candidateId = substr((string) $postId, 7);
                $dbDiscoveryItem = DiscoveryItem::query()
                    ->where('project_id', $dbProject->id)
                    ->where('id', $candidateId)
                    ->first();
                if ($dbDiscoveryItem) {
                    $queueRecord = $this->discoveryItemToQueueRecord($dbDiscoveryItem, $actionTag);
                }
            } else {
                [$platform, $rowNumber] = $this->parseDiscoveryId((string) $postId);
                $sheetName = $platform === 'instagram' ? 'Instagram_Posts_Raw' : 'TikTok_Posts_Raw';
                $rawRow = collect($this->sheets->getRows($sheetId, $sheetName))
                    ->first(fn (array $row) => (int) ($row['_row_number'] ?? 0) === $rowNumber);

                if ($rawRow) {
                    $queueRecord = $this->discoveryRowToQueueRecord($platform, $rawRow, $actionTag);
                }
            }

            if ($queueRecord === null) {
                $skipped++;
                continue;
            }

            $sheetTarget = $this->queueSheetForPlatform((string) $queueRecord['platform']);
            $key = strtolower($queueRecord['platform']) . '|' . strtolower($queueRecord['handle']) . '|' . strtolower($queueRecord['url']);

            if (isset($existingLookup[$sheetTarget][$key])) {
                if ($dbDiscoveryItem) {
                    $dbDiscoveryItem->promoted_to_enrichment_at = $dbDiscoveryItem->promoted_to_enrichment_at ?: now();
                    $dbDiscoveryItem->save();
                }
                $skipped++;
                continue;
            }

            $queueRecordsBySheet[$sheetTarget][] = $queueRecord;
            $queueRecordsBySheet['Profile_URL_Queue_All'][] = $queueRecord;
            $existingLookup[$sheetTarget][$key] = true;
            $existingLookup['Profile_URL_Queue_All'][$key] = true;
            $createdItems[] = $queueRecord;
            $created++;

            if ($dbDiscoveryItem) {
                $dbDiscoveryItem->promoted_to_enrichment_at = now();
                $dbDiscoveryItem->save();
            }
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

        $identityId = $this->extractCreatorIdentityId($row);
        $linkedProfiles = $this->extractTaggedValue((string) ($row['Notes'] ?? ''), 'linked_profiles') ?? '';
        $linkedProfileCount = $linkedProfiles !== '' ? count(array_filter(explode(',', $linkedProfiles))) : ($identityId ? 1 : 0);

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
            'creatorIdentityId' => $identityId,
            'linkedProfileCount' => $linkedProfileCount,
        ];
    }

    private function normalizeCreatorStatus(string $status, string $enrichmentStatus = 'pending'): string
    {
        return $this->lifecycle->normalizeState($status, $enrichmentStatus);
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
            $item['authorUrl'] ?? '',
            $item['caption'] ?? '',
            $item['postUrl'] ?? '',
            $item['platform'] ?? '',
            data_get($item, 'raw.ownerFullName', ''),
            data_get($item, 'raw.ownerUsername', ''),
            data_get($item, 'raw.authorMeta.name', ''),
            data_get($item, 'raw.authorMeta.nickName', ''),
            implode(' ', (array) data_get($item, 'raw.hashtags', [])),
        ]);
    }

    private function matchesTextSearch(string $needle, array $haystacks): bool
    {
        $needle = Str::lower(trim($needle));
        if ($needle === '') {
            return true;
        }

        foreach ($haystacks as $haystack) {
            $text = Str::lower(trim((string) $haystack));
            if ($text !== '' && str_contains($text, $needle)) {
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

    private function loadDiscoveryItemsFromDatabase(string $sheetId, array $filters): ?array
    {
        if (!$this->mirror->enabled()) {
            return null;
        }

        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return null;
        }

        $platforms = array_values(array_filter((array) ($filters['platforms'] ?? []), fn ($value) => in_array($value, ['instagram', 'tiktok'], true)));
        $query = DiscoveryItem::query()->where('project_id', $project->id);
        if ($platforms !== []) {
            $query->whereIn('platform', $platforms);
        }

        $rows = $query->orderByDesc('discovered_at')->orderByDesc('created_at')->get();
        if ($rows->isEmpty()) {
            return [
                'items' => [],
                'total' => 0,
                'raw_total' => 0,
                'deduped' => (bool) ($filters['dedupe'] ?? true),
                'duplicate_groups' => 0,
            ];
        }

        $search = Str::lower(trim((string) ($filters['search'] ?? '')));
        $dedupe = (bool) ($filters['dedupe'] ?? true);
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $limit = max(1, (int) ($filters['limit'] ?? 200));

        $normalized = [];
        foreach ($rows as $row) {
            $item = $this->discoveryItemToListItem($row);
            if ($search !== '' && !$this->matchesDiscoverySearch($item, $search)) {
                continue;
            }
            $normalized[] = $item;
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

        return [
            'items' => array_values(array_slice($normalized, $offset, $limit)),
            'total' => count($normalized),
            'raw_total' => $rawTotal,
            'deduped' => $dedupe,
            'duplicate_groups' => $duplicateGroups,
        ];
    }

    private function discoveryItemToListItem(DiscoveryItem $item): array
    {
        $metrics = is_array($item->metrics) ? $item->metrics : [];
        $raw = is_array($item->raw_payload) ? $item->raw_payload : [];
        $timestamp = $item->discovered_at?->toISOString() ?: (string) data_get($raw, 'timestamp', '');
        $handle = $this->normalizeHandle((string) ($item->handle ?: $item->username ?: ''));
        $duplicateKey = strtolower(trim((string) ($item->duplicate_key ?: ($item->post_url ?: ($item->platform . '|' . $handle . '|' . $item->caption)))));

        return [
            'id' => 'discdb:' . $item->id,
            'rowId' => 'discdb:' . $item->id,
            'sourceSheet' => 'discovery_items',
            'sourceRowNumber' => null,
            'platform' => (string) $item->platform,
            'authorHandle' => $handle,
            'authorUrl' => (string) ($item->profile_url ?: ''),
            'caption' => (string) ($item->caption ?: ''),
            'likes' => $this->sanitizeMetric($metrics['likes'] ?? null),
            'comments' => $this->sanitizeMetric($metrics['comments'] ?? null),
            'views' => $this->sanitizeMetric($metrics['views'] ?? $metrics['playCount'] ?? null),
            'postUrl' => (string) ($item->post_url ?: ''),
            'timestamp' => $timestamp,
            'duplicateKey' => $duplicateKey,
            'raw' => array_merge($raw, [
                'ownerFullName' => (string) ($item->full_name ?: ''),
                'ownerUsername' => ltrim($handle, '@'),
                'authorMeta' => [
                    'name' => ltrim($handle, '@'),
                    'nickName' => (string) ($item->full_name ?: ''),
                ],
                'hashtags' => is_array($item->hashtags) ? $item->hashtags : [],
            ]),
        ];
    }

    private function resolveMessageTemplateForRoute(string $sheetId, string $id): ?MessageTemplate
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return null;
        }

        $id = trim($id);
        if ($id === '') {
            return null;
        }

        if (Str::startsWith($id, 'msgdb:')) {
            $candidate = substr($id, 6);
            return MessageTemplate::query()->where('project_id', $project->id)->where('id', $candidate)->first();
        }

        if (Str::startsWith($id, 'msg:')) {
            $rowNumber = (int) substr($id, 4);
            if ($rowNumber > 1) {
                return MessageTemplate::query()->where('project_id', $project->id)->where('metadata->source_row_number', $rowNumber)->first();
            }
        }

        if (Str::isUuid($id)) {
            return MessageTemplate::query()->where('project_id', $project->id)->where('id', $id)->first();
        }

        return null;
    }

    private function syncMessageTemplateToSheet(string $sheetId, MessageTemplate $template): array
    {
        $record = $this->messagePayloadToSheetRecord([
            'angleId' => $template->angle_id,
            'platform' => $template->platform,
            'niche' => $template->niche,
            'stage' => $template->stage,
            'copy' => $template->copy,
            'notes' => $template->notes,
            'psychologicalTrigger' => $template->psychological_trigger,
        ]);

        $metadata = is_array($template->metadata) ? $template->metadata : [];
        $rowNumber = (int) ($metadata['source_row_number'] ?? 0);

        try {
            if ($rowNumber > 1) {
                $this->sheets->updateAssocRow($sheetId, 'Message_Library', $rowNumber, $record);
                return ['mode' => 'updated', 'rowNumber' => $rowNumber];
            }

            $headers = $this->sheets->getHeaders($sheetId, 'Message_Library');
            $this->sheets->appendAssocRows($sheetId, 'Message_Library', [$record], $headers);
            $rows = $this->sheets->getRows($sheetId, 'Message_Library');
            $rowNumber = (int) (($rows[count($rows) - 1]['_row_number'] ?? 0));
            if ($rowNumber > 1) {
                $metadata['source_row_number'] = $rowNumber;
                $template->metadata = $metadata;
                $template->save();
            }
            return ['mode' => 'created', 'rowNumber' => $rowNumber];
        } catch (\Throwable $e) {
            Log::warning('Message_Library sheet sync failed after database template write', [
                'sheet_id' => $sheetId,
                'template_id' => $template->id,
                'row_number' => $rowNumber,
                'error' => $e->getMessage(),
            ]);
            return ['mode' => 'failed', 'rowNumber' => $rowNumber, 'error' => $e->getMessage()];
        }
    }

    private function discoveryItemToQueueRecord(DiscoveryItem $item, string $actionTag): ?array
    {
        $platform = strtolower(trim((string) $item->platform));
        if (!in_array($platform, ['instagram', 'tiktok'], true)) {
            return null;
        }

        $handle = $this->normalizeHandle((string) ($item->handle ?: $item->username ?: ''));
        $username = ltrim($handle, '@');
        $url = trim((string) ($item->profile_url ?: ''));

        if ($username === '' && $url === '') {
            return null;
        }

        if ($url === '') {
            $url = $platform === 'instagram' ? $this->instagramProfileUrl($username) : $this->tiktokProfileUrl($username);
        }

        $addedAt = now()->toDateTimeString();
        return [
            'platform' => $platform,
            'handle' => $handle,
            'url' => $url,
            'username' => $username,
            'name' => (string) ($item->full_name ?: ''),
            'country' => '',
            'city' => '',
            'primary_language' => '',
            'niche_category' => '',
            'status' => 'queued',
            'priority_for_enrichment' => 'normal',
            'source_notes' => sprintf('%s; added_at=%s; source_discovery_id=%s; source_post_url=%s', $actionTag, $addedAt, (string) $item->id, (string) ($item->post_url ?: '')),
        ];
    }

    private function parseDiscoveryId(string $id): array
    {
        $parts = explode(':', $id);
        if (count($parts) !== 2 || !in_array($parts[0], ['instagram', 'tiktok'], true) || !is_numeric($parts[1])) {
            throw new RuntimeException('Invalid discovery row id');
        }
        return [$parts[0], (int) $parts[1]];
    }

    private function resolveSelectedMergeTargets(string $platform, array $selectors, array $queueRows, array $sourceRows): array
    {
        $maxQueueRowNumber = 1;
        foreach ($queueRows as $row) {
            $maxQueueRowNumber = max($maxQueueRowNumber, (int) ($row['_row_number'] ?? 1));
        }

        $selectedQueueRowNumbers = [];
        $unresolvedSelectors = [];

        foreach ($selectors as $selector) {
            $resolvedQueueRow = $this->parseQueueSelector((string) $selector, $platform, $maxQueueRowNumber);
            if ($resolvedQueueRow !== null) {
                $selectedQueueRowNumbers[] = $resolvedQueueRow;
                continue;
            }

            $unresolvedSelectors[] = (string) $selector;
        }

        $selectedQueueRowNumbers = array_values(array_unique(array_filter(array_map('intval', $selectedQueueRowNumbers), fn (int $row) => $row > 1)));
        $selectedLookup = array_fill_keys($selectedQueueRowNumbers, true);
        $selectedQueueRows = array_values(array_filter($queueRows, fn (array $row) => isset($selectedLookup[(int) ($row['_row_number'] ?? 0)])));

        $sourceRowNumbers = $this->matchQueueRowsToEnrichedRowNumbers($platform, $selectedQueueRows, $sourceRows);
        $resolvedBy = $selectedQueueRows !== [] ? ['queue_rows'] : [];

        if ($unresolvedSelectors !== []) {
            $fallbackSourceRows = $this->matchSelectorsToEnrichedRowNumbers($platform, $unresolvedSelectors, $sourceRows);
            if ($fallbackSourceRows !== []) {
                $sourceRowNumbers = array_values(array_unique(array_merge($sourceRowNumbers, $fallbackSourceRows)));
                $resolvedBy[] = 'source_selectors';
            }
        }

        return [
            'selectionMode' => $selectedQueueRows !== [] ? 'queue_or_mixed' : ($unresolvedSelectors !== [] ? 'source_selector' : 'empty'),
            'resolvedBy' => $resolvedBy,
            'selectedQueueRowNumbers' => $selectedQueueRowNumbers,
            'selectedQueueRows' => $selectedQueueRows,
            'sourceRowNumbers' => $sourceRowNumbers,
        ];
    }

    private function parseQueueSelector(string $id, string $platform, int $maxQueueRowNumber): ?int
    {
        $prefix = $platform . ':queue:';
        if (str_starts_with($id, $prefix)) {
            $value = substr($id, strlen($prefix));
            if (is_numeric($value)) {
                $rowNumber = (int) $value;
                return $rowNumber > 1 ? $rowNumber : null;
            }
            return null;
        }

        if (is_numeric($id)) {
            $rowNumber = (int) $id;
            if ($rowNumber > 1 && $rowNumber <= $maxQueueRowNumber) {
                return $rowNumber;
            }
        }

        return null;
    }

    private function matchSelectorsToEnrichedRowNumbers(string $platform, array $selectors, array $sourceRows): array
    {
        $lookup = [];
        foreach ($sourceRows as $row) {
            $rowNumber = (int) ($row['_row_number'] ?? 0);
            if ($rowNumber <= 1) {
                continue;
            }
            foreach ($this->enrichedSelectorKeys($platform, $row) as $key) {
                $lookup[$key] = $rowNumber;
            }
        }

        $rowNumbers = [];
        foreach ($selectors as $selector) {
            foreach ($this->selectorLookupKeys($platform, (string) $selector) as $key) {
                if (isset($lookup[$key])) {
                    $rowNumbers[] = (int) $lookup[$key];
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($rowNumbers)));
    }

    private function selectorLookupKeys(string $platform, string $selector): array
    {
        $selector = trim($selector);
        if ($selector === '') {
            return [];
        }

        $keys = [strtolower($selector)];

        $urlPrefix = $platform . ':source-url:';
        if (str_starts_with($selector, $urlPrefix)) {
            $decoded = rawurldecode(substr($selector, strlen($urlPrefix)));
            $decoded = rtrim(strtolower(trim($decoded)), '/');
            $keys[] = $decoded;
        }

        $idPrefix = $platform . ':source-id:';
        if (str_starts_with($selector, $idPrefix)) {
            $idValue = trim(substr($selector, strlen($idPrefix)));
            if ($idValue !== '') {
                $keys[] = strtolower($idValue);
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    private function enrichedSelectorKeys(string $platform, array $row): array
    {
        return array_values(array_unique(array_filter([
            strtolower(trim((string) ($row['apify_profile_id'] ?? $row['id'] ?? ''))),
            strtolower(rtrim(trim((string) ($row['profile_url'] ?? '')), '/')),
            strtolower(rtrim(trim((string) ($row['input_url'] ?? '')), '/')),
            strtolower($this->normalizeHandle((string) ($row['handle'] ?? $row['username'] ?? ''))),
            strtolower(trim((string) ($row['username'] ?? ''))),
        ])));
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

    private function extractCreatorIdentityId(array $row): ?string
    {
        $direct = trim((string) ($row['Creator_Identity_ID'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        return $this->extractTaggedValue((string) ($row['Notes'] ?? ''), 'creator_identity_id');
    }

    private function upsertTaggedValue(string $text, string $key, string $value): string
    {
        $value = trim($value);
        $pattern = '/(?:^|[;|\s])' . preg_quote($key, '/') . '=[^;|]*/';
        if (preg_match($pattern, $text)) {
            $updated = preg_replace($pattern, ' ' . $key . '=' . $value, $text, 1);
            return trim((string) $updated);
        }

        return trim($text . ' ' . $key . '=' . $value);
    }
}
