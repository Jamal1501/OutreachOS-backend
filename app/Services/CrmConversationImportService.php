<?php

namespace App\Services;

use App\Exceptions\CrmImportValidationException;
use App\Models\CreatorProfile;
use App\Models\CrmImportBatch;
use App\Models\CrmImportBatchItem;
use App\Models\OutreachEvent;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrmConversationImportService
{
    private const FIELD_ALIASES = [
        'platform' => ['platform', 'social_platform', 'network'],
        'handle' => ['handle', 'username', 'creator_handle', 'creator_username', 'instagram_handle', 'tiktok_handle'],
        'profile_url' => ['profile_url', 'profile_link', 'social_url', 'instagram_url', 'tiktok_url'],
        'email' => ['email', 'contact_email', 'creator_email', 'business_email'],
        'direction' => ['direction', 'message_direction', 'inbound_outbound', 'sent_received', 'type'],
        'message' => ['message', 'message_text', 'body', 'content', 'text'],
        'sent_at' => ['sent_at', 'date', 'timestamp', 'message_date', 'created_at', 'occurred_at'],
        'channel' => ['channel', 'outreach_channel', 'message_channel'],
        'conversation_url' => ['conversation_url', 'thread_url', 'message_url', 'conversation_link'],
        'sender_account' => ['sender_account', 'sender', 'from_account', 'team_member'],
        'status' => ['status', 'delivery_status', 'message_status'],
    ];

    public function __construct(
        private ProjectResolverService $projects,
        private CreatorRelationshipTimelineService $relationshipTimeline,
    ) {}

    public function preview(UploadedFile $file, array $mapping = []): array
    {
        $data = $this->readCsvData($file);
        $headers = array_map(fn (array $header) => $header['label'], $data['headers']);
        $suggestedMapping = $this->suggestMapping($headers);

        return [
            'headers' => $headers,
            'fields' => $this->importFields(),
            'suggestedMapping' => $suggestedMapping,
            'sampleRows' => array_slice($data['displayRows'], 0, 5),
            'rowsRead' => count($data['rows']),
        ];
    }

    public function import(string $workbookId, UploadedFile $file, array $mapping, array $options = []): array
    {
        $project = $this->projects->resolveByWorkbookId($workbookId);
        $data = $this->readCsvData($file);
        $mapping = $this->normalizeMapping($mapping);
        $defaultPlatform = $this->normalizePlatform((string) ($options['defaultPlatform'] ?? ''));

        $createdEvents = 0;
        $duplicateEvents = 0;
        $errorCount = 0;
        $errors = [];
        $matchedProfiles = [];
        $batch = null;

        DB::transaction(function () use ($project, $file, $data, $mapping, $defaultPlatform, $options, &$createdEvents, &$duplicateEvents, &$errorCount, &$errors, &$matchedProfiles, &$batch): void {
            $batch = CrmImportBatch::query()->create([
                'workspace_id' => (string) $project->workspace_id,
                'project_id' => $project->id,
                'created_by_user_id' => trim((string) ($options['createdByUserId'] ?? '')) ?: null,
                'original_filename' => $file->getClientOriginalName(),
                'status' => 'activated',
                'row_count' => count($data['rows']),
                'settings' => [
                    'importType' => 'conversation_history',
                    'defaultPlatform' => $defaultPlatform ?: null,
                ],
                'activated_at' => now(),
            ]);

            foreach ($data['rows'] as $index => $row) {
                $rowNumber = (int) ($row['__row_number'] ?? ($index + 2));
                $directionValue = $this->value($row, 'direction', $mapping);
                $direction = $this->normalizeDirection($directionValue);
                $message = trim($this->value($row, 'message', $mapping));
                $sentAtValue = $this->value($row, 'sent_at', $mapping);
                $sentAt = $this->parseDate($sentAtValue);
                $profile = $this->resolveProfile($project, $row, $mapping, $defaultPlatform);

                $reason = null;
                if ($direction === '') {
                    $reason = 'Direction must identify a sent or received message.';
                } elseif ($message === '') {
                    $reason = 'Message text is empty.';
                } elseif ($sentAt === null) {
                    $reason = 'Message date is missing or could not be read.';
                } elseif ($profile === null) {
                    $reason = 'No existing CRM creator matched this row.';
                }

                if ($reason !== null) {
                    $errorCount++;
                    if (count($errors) < 200) {
                        $errors[] = [
                            'rowNumber' => $rowNumber,
                            'reason' => $reason,
                            'platform' => $this->value($row, 'platform', $mapping),
                            'handle' => $this->value($row, 'handle', $mapping),
                            'profileUrl' => $this->value($row, 'profile_url', $mapping),
                            'row' => $this->errorRowPreview((array) ($row['__display'] ?? [])),
                        ];
                    }

                    continue;
                }

                $channel = Str::limit(trim($this->value($row, 'channel', $mapping)) ?: ($profile->preferred_channel ?: $profile->platform), 100, '');
                $eventKey = $this->eventKey($project, $profile, $direction, $message, $sentAt, $channel);
                $event = OutreachEvent::query()->firstOrCreate(
                    [
                        'project_id' => $project->id,
                        'external_event_key' => $eventKey,
                    ],
                    [
                        'creator_profile_id' => $profile->id,
                        'platform' => $profile->platform,
                        'handle' => $profile->handle,
                        'channel' => $channel,
                        'event_type' => $direction === 'inbound' ? 'CREATOR_REPLY' : 'MESSAGE_SENT',
                        'sender_account' => Str::limit(trim($this->value($row, 'sender_account', $mapping)), 255, '') ?: null,
                        'sent_at' => $sentAt,
                        'status' => Str::limit(trim($this->value($row, 'status', $mapping)), 100, '') ?: 'IMPORTED',
                        'url' => $this->safeUrl($this->value($row, 'conversation_url', $mapping)),
                        'notes' => 'Imported from historical conversation data.',
                        'metadata' => [
                            'message_text' => Str::limit($message, 10000, '…'),
                            'message_direction' => $direction,
                            'import_batch_id' => (string) $batch->id,
                            'import_filename' => $file->getClientOriginalName(),
                            'import_row_number' => $rowNumber,
                            'import_type' => 'conversation_history',
                        ],
                    ],
                );

                if (! $event->wasRecentlyCreated) {
                    $duplicateEvents++;

                    continue;
                }

                $createdEvents++;
                $matchedProfiles[(string) $profile->id] = $profile;
                $this->relationshipTimeline->recordOutreachEvent($event, $project);
            }

            foreach ($matchedProfiles as $profile) {
                CrmImportBatchItem::query()->create([
                    'batch_id' => $batch->id,
                    'creator_id' => $profile->creator_id,
                    'creator_profile_id' => $profile->id,
                    'action' => 'history_only',
                ]);
            }

            $batch->summary = [
                'importType' => 'conversation_history',
                'rowsRead' => count($data['rows']),
                'createdEvents' => $createdEvents,
                'duplicateEvents' => $duplicateEvents,
                'matchedProfiles' => count($matchedProfiles),
                'errorCount' => $errorCount,
            ];
            $batch->save();
        });

        return [
            'projectId' => $project->id,
            'rowsRead' => count($data['rows']),
            'createdEvents' => $createdEvents,
            'duplicateEvents' => $duplicateEvents,
            'matchedProfiles' => count($matchedProfiles),
            'errorCount' => $errorCount,
            'errors' => $errors,
            'errorsTruncated' => $errorCount > count($errors),
            'batchId' => (string) $batch->id,
            'batchStatus' => (string) $batch->status,
        ];
    }

    private function resolveProfile(Project $project, array $row, array $mapping, string $defaultPlatform): ?CreatorProfile
    {
        $rawPlatform = $this->value($row, 'platform', $mapping);
        $rawHandle = $this->value($row, 'handle', $mapping);
        $profileUrl = $this->value($row, 'profile_url', $mapping);
        $platform = $this->resolvePlatform($rawPlatform, $rawHandle, $profileUrl, $defaultPlatform);
        $handle = $this->normalizeHandle($rawHandle !== '' ? $rawHandle : $profileUrl, $platform);

        if ($platform !== '' && $handle !== '') {
            $profile = CreatorProfile::query()
                ->where('project_id', $project->id)
                ->where('platform', $platform)
                ->whereRaw('LOWER(handle) = ?', [$handle])
                ->first();
            if ($profile) {
                return $profile;
            }
        }

        $email = Str::lower(trim($this->value($row, 'email', $mapping)));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $profiles = CreatorProfile::query()
            ->where('project_id', $project->id)
            ->whereHas('creator', fn ($query) => $query->whereRaw('LOWER(primary_email) = ?', [$email]))
            ->limit(2)
            ->get();

        return $profiles->count() === 1 ? $profiles->first() : null;
    }

    private function eventKey(Project $project, CreatorProfile $profile, string $direction, string $message, CarbonImmutable $sentAt, string $channel): string
    {
        return 'history-import:'.hash('sha256', implode('|', [
            (string) $project->id,
            (string) $profile->id,
            $direction,
            $sentAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            Str::lower(trim($channel)),
            trim($message),
        ]));
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2000 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(Str::lower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true) ? $value : null;
    }

    private function normalizeDirection(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^a-z]+/', '_', $value) ?: '';
        $value = trim($value, '_');

        if (Str::contains($value, ['inbound', 'incoming', 'received', 'receive', 'reply', 'replied', 'creator', 'from_them'])) {
            return 'inbound';
        }
        if (Str::contains($value, ['outbound', 'outgoing', 'sent', 'send', 'team', 'agency', 'from_us'])) {
            return 'outbound';
        }

        return '';
    }

    private function importFields(): array
    {
        return [
            ['key' => 'platform', 'label' => 'Platform', 'required' => false],
            ['key' => 'handle', 'label' => 'Handle', 'required' => false],
            ['key' => 'profile_url', 'label' => 'Profile URL', 'required' => false],
            ['key' => 'email', 'label' => 'Email', 'required' => false],
            ['key' => 'direction', 'label' => 'Direction', 'required' => true],
            ['key' => 'message', 'label' => 'Message', 'required' => true],
            ['key' => 'sent_at', 'label' => 'Message date', 'required' => true],
            ['key' => 'channel', 'label' => 'Channel', 'required' => false],
            ['key' => 'conversation_url', 'label' => 'Conversation URL', 'required' => false],
            ['key' => 'sender_account', 'label' => 'Sender account', 'required' => false],
            ['key' => 'status', 'label' => 'Status', 'required' => false],
        ];
    }

    private function readCsvData(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $path || ! is_readable($path)) {
            throw new CrmImportValidationException('Uploaded file could not be read.');
        }

        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new CrmImportValidationException('Uploaded file could not be opened.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false || trim(preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? '') === '') {
                throw new CrmImportValidationException('The CSV file is empty. Add a header row and at least one message.');
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
            $duplicates = array_keys(array_filter(array_count_values(array_filter($normalizedHeaders)), fn (int $count) => $count > 1));
            if ($duplicates !== []) {
                throw new CrmImportValidationException('Duplicate CSV columns were found after normalization: '.implode(', ', $duplicates).'. Rename them and try again.');
            }

            $rows = [];
            $displayRows = [];
            $rowNumber = 1;
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;
                if (count($rows) >= 10000) {
                    throw new CrmImportValidationException('Conversation history import limit is 10,000 rows. Split the file and try again.');
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
                throw new CrmImportValidationException('The CSV contains headers but no message rows.');
            }

            return ['headers' => $headerMeta, 'rows' => $rows, 'displayRows' => $displayRows];
        } finally {
            fclose($handle);
        }
    }

    private function suggestMapping(array $headers): array
    {
        $normalizedHeaders = [];
        foreach ($headers as $header) {
            $normalizedHeaders[$this->normalizeHeader((string) $header)] = (string) $header;
        }

        $mapping = [];
        foreach (self::FIELD_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (isset($normalizedHeaders[$this->normalizeHeader($alias)])) {
                    $mapping[$field] = $normalizedHeaders[$this->normalizeHeader($alias)];
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
            $header = trim((string) $header);
            if ($header !== '' && $header !== '__skip__' && isset(self::FIELD_ALIASES[(string) $field])) {
                $normalized[(string) $field] = $this->normalizeHeader($header);
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

    private function resolvePlatform(string $rawPlatform, string $rawHandle, string $profileUrl, string $defaultPlatform): string
    {
        if (trim($rawPlatform) !== '') {
            return $this->normalizePlatform($rawPlatform);
        }

        return $this->inferPlatformFromUrl($profileUrl) ?: $this->inferPlatformFromUrl($rawHandle) ?: $defaultPlatform;
    }

    private function normalizePlatform(string $value): string
    {
        return match (Str::lower(trim($value))) {
            'instagram', 'insta', 'ig', 'instagram reels', 'instagram_reels' => 'instagram',
            'tiktok', 'tik tok', 'tik_tok', 'tt' => 'tiktok',
            'email', 'e-mail', 'mail' => 'email',
            default => '',
        };
    }

    private function inferPlatformFromUrl(string $value): string
    {
        $host = Str::lower((string) parse_url($this->normalizeProfileUrlValue($value) ?: trim($value), PHP_URL_HOST));
        if ($host === 'instagram.com' || Str::endsWith($host, '.instagram.com')) {
            return 'instagram';
        }
        if ($host === 'tiktok.com' || Str::endsWith($host, '.tiktok.com')) {
            return 'tiktok';
        }

        return '';
    }

    private function normalizeHandle(string $value, string $platform): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($platform === 'email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? Str::lower($value) : '';
        }

        $url = $this->normalizeProfileUrlValue($value);
        if ($url !== null) {
            $segments = array_values(array_filter(explode('/', trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/'))));
            if ($platform === 'instagram' && isset($segments[0]) && ! in_array(Str::lower($segments[0]), ['p', 'reel', 'reels', 'stories', 'explore'], true)) {
                $value = $segments[0];
            } elseif ($platform === 'tiktok' && isset($segments[0]) && Str::startsWith($segments[0], '@')) {
                $value = $segments[0];
            } else {
                return '';
            }
        }

        $value = trim(explode('?', explode('#', $value)[0])[0], "/@ \t\n\r\0\x0B");
        if ($value === '' || preg_match('/^[a-z0-9._-]{1,100}$/i', $value) !== 1) {
            return '';
        }

        return '@'.Str::lower($value);
    }

    private function normalizeProfileUrlValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || ! Str::contains(Str::lower($value), ['instagram.com', 'tiktok.com'])) {
            return null;
        }
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://'.$value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = Str::lower(trim($header));
        $header = str_replace([' ', '-', '.', '/', '\\'], '_', $header);
        $header = preg_replace('/_+/', '_', $header) ?? $header;

        return trim($header, '_');
    }

    private function detectDelimiter(string $line): string
    {
        $candidates = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    private function errorRowPreview(array $row): array
    {
        $preview = [];
        foreach (array_slice($row, 0, 50, true) as $header => $value) {
            $preview[(string) $header] = Str::limit((string) $value, 500, '…');
        }

        return $preview;
    }
}
