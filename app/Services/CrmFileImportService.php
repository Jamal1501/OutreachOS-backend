<?php

namespace App\Services;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CrmFileImportService
{
    private const FIELD_ALIASES = [
        'platform' => ['platform', 'social_platform', 'channel', 'network'],
        'handle' => ['handle', 'username', 'user_name', 'creator_username', 'creator_handle', 'creator', 'profile', 'account'],
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
        'profile_url' => ['profile_url', 'url', 'dm_link', 'dm_url', 'link'],
        'avatar_url' => ['profile_pic_url', 'avatar_url', 'profile_picture'],
        'preferred_channel' => ['preferred_channel', 'preferred_contact_channel'],
        'duplicate_flag' => ['duplicate_flag', 'duplicate'],
        'accepted' => ['accepted_(y/n)', 'accepted', 'accepted_flag'],
        'follow_up_needed' => ['follow_up_needed_(y/n)', 'follow_up_needed'],
    ];

    public function __construct(private ProjectResolverService $projects)
    {
    }

    public function previewCreatorsCsv(UploadedFile $file): array
    {
        $data = $this->readCsvData($file);
        $headers = array_map(fn (array $header) => $header['label'], $data['headers']);

        return [
            'headers' => $headers,
            'fields' => $this->importFields(),
            'suggestedMapping' => $this->suggestMapping($headers),
            'sampleRows' => array_slice($data['displayRows'], 0, 5),
            'rowsRead' => count($data['rows']),
        ];
    }

    public function importCreatorsCsv(string $workbookId, UploadedFile $file, array $mapping = []): array
    {
        $project = $this->projects->resolveByWorkbookId($workbookId);
        $data = $this->readCsvData($file);
        $rows = $data['rows'];
        $mapping = $this->normalizeMapping($mapping);
        $createdCreators = 0;
        $createdProfiles = 0;
        $updatedProfiles = 0;
        $skipped = 0;

        DB::transaction(function () use ($project, $rows, $file, $mapping, &$createdCreators, &$createdProfiles, &$updatedProfiles, &$skipped): void {
            foreach ($rows as $index => $row) {
                $platform = $this->normalizePlatform($this->value($row, 'platform', $mapping));
                $handle = $this->normalizeHandle($this->value($row, 'handle', $mapping));

                if ($platform === '' || $handle === '') {
                    $skipped++;
                    continue;
                }

                $email = $this->nullableString($this->value($row, 'email', $mapping));
                $displayName = $this->nullableString($this->value($row, 'name', $mapping));
                $identityKey = $this->creatorIdentityKey($platform, $handle, $email, $displayName);

                $creator = Creator::query()
                    ->where('project_id', $project->id)
                    ->where('external_identity_key', $identityKey)
                    ->first();

                if (!$creator && $email) {
                    $creator = Creator::query()
                        ->where('project_id', $project->id)
                        ->where('primary_email', $email)
                        ->first();
                }

                if (!$creator) {
                    $creator = new Creator([
                        'project_id' => $project->id,
                        'external_identity_key' => $identityKey,
                    ]);
                    $createdCreators++;
                }

                $creator->display_name = $displayName ?: ($creator->display_name ?: ltrim($handle, '@'));
                $creator->primary_email = $email ?: $creator->primary_email;
                $creator->country = $this->nullableString($this->value($row, 'country', $mapping)) ?: $creator->country;
                $creator->city = $this->nullableString($this->value($row, 'city', $mapping)) ?: $creator->city;
                $creator->primary_language = $this->nullableString($this->value($row, 'language', $mapping)) ?: $creator->primary_language;
                $creator->niche_category = $this->nullableString($this->value($row, 'niche', $mapping)) ?: $creator->niche_category;
                $creator->notes = $this->nullableString($this->value($row, 'notes', $mapping)) ?: $creator->notes;
                $creator->metadata = array_filter(array_merge((array) ($creator->metadata ?? []), [
                    'last_file_import' => [
                        'filename' => $file->getClientOriginalName(),
                        'row_number' => $index + 2,
                    ],
                ]));
                $creator->save();

                $profile = CreatorProfile::query()
                    ->where('project_id', $project->id)
                    ->where('platform', $platform)
                    ->where('handle', $handle)
                    ->first();

                $wasNewProfile = !$profile;
                $profile ??= new CreatorProfile([
                    'project_id' => $project->id,
                    'platform' => $platform,
                    'handle' => $handle,
                ]);

                $profile->creator_id = $creator->id;
                $profile->username = ltrim($handle, '@');
                $profileUrl = $this->nullableString($this->value($row, 'profile_url', $mapping));
                $profile->profile_url = $profileUrl ?: $profile->profile_url;
                $profile->dm_link = $profileUrl ?: $profile->dm_link;
                $profile->profile_pic_url = $this->nullableString($this->value($row, 'avatar_url', $mapping)) ?: $profile->profile_pic_url;
                $profile->status = $this->nullableString($this->value($row, 'status', $mapping)) ?: ($profile->status ?: 'IMPORTED');
                $profile->lifecycle_state = $this->normalizeLifecycleState((string) $profile->status);
                $profile->followers_count = $this->nullableInt($this->value($row, 'followers', $mapping)) ?? $profile->followers_count;
                $profile->engagement_rate_pct = $this->nullableFloat($this->value($row, 'engagement_rate', $mapping)) ?? $profile->engagement_rate_pct;
                $profile->preferred_channel = $this->nullableString($this->value($row, 'preferred_channel', $mapping)) ?: $profile->preferred_channel;
                $profile->value_score = $this->nullableInt($this->value($row, 'value_score', $mapping)) ?? $profile->value_score;
                $profile->value_bar = $this->nullableString($this->value($row, 'value_bar', $mapping)) ?: $profile->value_bar;
                $profile->duplicate_flag = $this->nullableString($this->value($row, 'duplicate_flag', $mapping)) ?: $profile->duplicate_flag;
                $profile->accepted_flag = $this->parseYesNo($this->value($row, 'accepted', $mapping)) ?? (bool) $profile->accepted_flag;
                $profile->follow_up_needed = $this->parseYesNo($this->value($row, 'follow_up_needed', $mapping)) ?? (bool) $profile->follow_up_needed;
                $profile->source_provider = 'file_upload';
                $profile->source_reference = 'csv:' . $file->getClientOriginalName() . ':' . ($index + 2);
                $profile->source_metadata = array_filter(array_merge((array) ($profile->source_metadata ?? []), [
                    'import_filename' => $file->getClientOriginalName(),
                    'import_row_number' => $index + 2,
                    'imported_from' => 'crm_file_upload',
                ]));
                $profile->last_synced_at = now();
                $profile->save();

                if ($wasNewProfile) {
                    $createdProfiles++;
                } else {
                    $updatedProfiles++;
                }
            }
        });

        return [
            'projectId' => $project->id,
            'rowsRead' => count($rows),
            'createdCreators' => $createdCreators,
            'createdProfiles' => $createdProfiles,
            'updatedProfiles' => $updatedProfiles,
            'skipped' => $skipped,
            'totalProfiles' => CreatorProfile::query()->where('project_id', $project->id)->count(),
        ];
    }

    private function readCsvData(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (!$path || !is_readable($path)) {
            throw new RuntimeException('Uploaded file could not be read.');
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Uploaded file could not be opened.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                return [];
            }
            $delimiter = $this->detectDelimiter($firstLine);
            rewind($handle);

            $headers = fgetcsv($handle, 0, $delimiter);
            if (!is_array($headers) || $headers === []) {
                return [];
            }

            $headerMeta = array_map(fn ($header) => [
                'label' => trim((string) $header),
                'key' => $this->normalizeHeader((string) $header),
            ], $headers);
            $normalizedHeaders = array_column($headerMeta, 'key');
            $rows = [];
            $displayRows = [];
            $rowLimit = 5000;

            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (count($rows) >= $rowLimit) {
                    throw new RuntimeException("CSV import limit is {$rowLimit} rows.");
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
                    $rows[] = $row;
                    if (count($displayRows) < 5) {
                        $displayRows[] = $displayRow;
                    }
                }
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
            ['key' => 'platform', 'label' => 'Platform', 'required' => true],
            ['key' => 'handle', 'label' => 'Handle', 'required' => true],
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

        return in_array($platform, ['instagram', 'tiktok', 'email'], true) ? $platform : '';
    }

    private function normalizeHandle(string $handle): string
    {
        $handle = trim($handle);
        if ($handle === '') {
            return '';
        }

        $handle = preg_replace('#^https?://(www\.)?(instagram\.com/|tiktok\.com/@?)#i', '', $handle) ?? $handle;
        $handle = trim($handle, "/ \t\n\r\0\x0B");

        return Str::startsWith($handle, '@') ? $handle : '@' . $handle;
    }

    private function creatorIdentityKey(string $platform, string $handle, ?string $email, ?string $displayName): string
    {
        if ($email) {
            return 'email:' . Str::lower($email);
        }

        if ($displayName) {
            return 'name:' . Str::lower($displayName);
        }

        return 'profile:' . $platform . '|' . Str::lower($handle);
    }

    private function normalizeLifecycleState(string $status): string
    {
        $state = Str::lower(trim(str_replace([' ', '-'], '_', $status)));

        return $state !== '' ? $state : 'imported';
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(string $value): ?int
    {
        $value = preg_replace('/[^0-9.-]/', '', trim($value)) ?? '';

        return $value !== '' && is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function nullableFloat(string $value): ?float
    {
        $value = preg_replace('/[^0-9.-]/', '', trim($value)) ?? '';

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
