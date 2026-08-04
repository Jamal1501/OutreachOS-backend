<?php

namespace App\Services;

use App\Exceptions\CrmImportValidationException;
use App\Models\CrmImportBatch;
use App\Models\CrmImportBatchItem;
use App\Models\MessageTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageTemplateImportService
{
    private const FIELD_ALIASES = [
        'name' => ['template_name', 'name', 'angle_id', 'angle_name', 'angle', 'template_id', 'title'],
        'copy' => ['copy', 'message', 'message_copy', 'message_text', 'template', 'dm_template', 'body'],
        'platform' => ['platform', 'best_for_platform', 'channel', 'network'],
        'stage' => ['stage', 'workflow_stage', 'message_stage', 'sequence_step', 'step'],
        'niche' => ['niche', 'category', 'vertical', 'best_for_niche'],
        'notes' => ['notes', 'note', 'comments', 'description'],
        'psychological_trigger' => ['psychological_trigger', 'trigger', 'psych_trigger', 'principle'],
    ];

    private const STAGES = ['cold_invite', 'follow_up', 'after_accept', 'negotiation', 'check_in', 'post_confirmation'];

    public function __construct(private ProjectResolverService $projects) {}

    public function preview(UploadedFile $file, array $mapping = [], array $stageMapping = []): array
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
            'workflow' => $this->analyzeStages($data['rows'], $mapping, $stageMapping),
        ];
    }

    public function import(string $workbookId, UploadedFile $file, array $mapping, array $options = []): array
    {
        $project = $this->projects->resolveByWorkbookId($workbookId);
        $data = $this->readCsvData($file);
        $mapping = $this->normalizeMapping($mapping);
        $stageMapping = $this->normalizeStageMapping((array) ($options['stageMapping'] ?? []));
        $analysis = $this->analyzeStages($data['rows'], $mapping, $stageMapping);
        if ($analysis['unknownStageCount'] > 0) {
            $unknown = collect($analysis['stages'])->where('requiresMapping', true)->pluck('source')->take(5)->implode(', ');
            throw new CrmImportValidationException('Map every template stage before importing. Still unmapped: '.$unknown.'.');
        }

        $defaultPlatform = $this->normalizePlatform((string) ($options['defaultPlatform'] ?? '')) ?: 'instagram';
        $overwriteExisting = filter_var($options['overwriteExisting'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $created = 0;
        $updated = 0;
        $duplicates = 0;
        $errorCount = 0;
        $errors = [];
        $batch = null;

        DB::transaction(function () use ($project, $file, $data, $mapping, $stageMapping, $defaultPlatform, $overwriteExisting, $options, &$created, &$updated, &$duplicates, &$errorCount, &$errors, &$batch): void {
            $batch = CrmImportBatch::query()->create([
                'workspace_id' => (string) $project->workspace_id,
                'project_id' => $project->id,
                'created_by_user_id' => trim((string) ($options['createdByUserId'] ?? '')) ?: null,
                'original_filename' => $file->getClientOriginalName(),
                'status' => 'activated',
                'row_count' => count($data['rows']),
                'settings' => [
                    'importType' => 'message_templates',
                    'defaultPlatform' => $defaultPlatform,
                    'overwriteExisting' => $overwriteExisting,
                    'stageMapping' => $stageMapping,
                ],
                'activated_at' => now(),
            ]);

            foreach ($data['rows'] as $index => $row) {
                $rowNumber = (int) ($row['__row_number'] ?? ($index + 2));
                $name = Str::limit(trim($this->value($row, 'name', $mapping)), 255, '');
                $copy = trim($this->value($row, 'copy', $mapping));
                $rawPlatform = $this->value($row, 'platform', $mapping);
                $platform = $rawPlatform === '' ? $defaultPlatform : $this->normalizePlatform($rawPlatform);
                $rawStage = $this->value($row, 'stage', $mapping);
                $stage = $rawStage === '' ? 'cold_invite' : $this->resolveStage($rawStage, $stageMapping);
                $reason = null;

                if ($name === '') {
                    $reason = 'Template name is empty.';
                } elseif ($copy === '') {
                    $reason = 'Message copy is empty.';
                } elseif ($platform === '') {
                    $reason = 'Platform is not supported.';
                } elseif ($stage === null) {
                    $reason = 'Template stage must be mapped.';
                }

                if ($reason !== null) {
                    $this->appendError($errors, $errorCount, $row, $rowNumber, $reason);

                    continue;
                }

                $existing = MessageTemplate::query()
                    ->where('project_id', $project->id)
                    ->whereRaw('LOWER(angle_id) = ?', [Str::lower($name)])
                    ->first();
                if ($existing && ! $overwriteExisting) {
                    $duplicates++;

                    continue;
                }
                if ($existing && ($existing->tasks()->exists() || $existing->outreachEvents()->exists())) {
                    $this->appendError($errors, $errorCount, $row, $rowNumber, 'This template already has activity and cannot be overwritten safely. Rename it to import a new version.');

                    continue;
                }

                $before = $existing ? $this->snapshot($existing) : null;
                $template = $existing ?: new MessageTemplate(['project_id' => $project->id]);
                $metadata = array_merge((array) ($template->metadata ?? []), [
                    'last_import_batch_id' => (string) $batch->id,
                    'last_import_filename' => $file->getClientOriginalName(),
                    'last_import_row_number' => $rowNumber,
                    'custom_fields' => array_merge(
                        (array) (($template->metadata ?? [])['custom_fields'] ?? []),
                        $this->customFields($row, $mapping),
                    ),
                ]);
                $template->fill([
                    'angle_id' => $name,
                    'platform' => $platform,
                    'niche' => Str::limit(trim($this->value($row, 'niche', $mapping)), 255, '') ?: null,
                    'stage' => $stage,
                    'copy' => Str::limit($copy, 20000, '…'),
                    'notes' => Str::limit(trim($this->value($row, 'notes', $mapping)), 5000, '…') ?: null,
                    'psychological_trigger' => Str::limit(trim($this->value($row, 'psychological_trigger', $mapping)), 2000, '…') ?: null,
                    'metadata' => $metadata,
                ]);

                if ($existing && $this->snapshot($template) == $before) {
                    $duplicates++;

                    continue;
                }

                $template->save();
                $after = $this->snapshot($template);
                CrmImportBatchItem::query()->create([
                    'batch_id' => $batch->id,
                    'message_template_id' => $template->id,
                    'action' => $existing ? 'template_updated' : 'template_created',
                    'template_before' => $before,
                    'template_after' => $after,
                ]);
                $existing ? $updated++ : $created++;
            }

            $batch->summary = [
                'importType' => 'message_templates',
                'rowsRead' => count($data['rows']),
                'createdTemplates' => $created,
                'updatedTemplates' => $updated,
                'duplicateTemplates' => $duplicates,
                'errorCount' => $errorCount,
            ];
            $batch->save();
        });

        return [
            'projectId' => $project->id,
            'rowsRead' => count($data['rows']),
            'createdTemplates' => $created,
            'updatedTemplates' => $updated,
            'duplicateTemplates' => $duplicates,
            'errorCount' => $errorCount,
            'errors' => $errors,
            'errorsTruncated' => $errorCount > count($errors),
            'batchId' => (string) $batch->id,
            'batchStatus' => (string) $batch->status,
        ];
    }

    private function analyzeStages(array $rows, array $mapping, array $stageMapping): array
    {
        $stageMapping = $this->normalizeStageMapping($stageMapping);
        $counts = [];
        foreach ($rows as $row) {
            $raw = trim($this->value($row, 'stage', $mapping));
            if ($raw === '') {
                continue;
            }
            $key = $this->stageKey($raw);
            $counts[$key] ??= ['source' => $raw, 'count' => 0];
            $counts[$key]['count']++;
        }

        $stages = collect($counts)->map(function (array $item, string $key) use ($stageMapping) {
            $mapped = $this->resolveStage($item['source'], $stageMapping);

            return [
                'source' => $item['source'],
                'key' => $key,
                'count' => $item['count'],
                'mappedTo' => $mapped,
                'requiresMapping' => $mapped === null,
            ];
        })->values()->all();

        return [
            'stages' => $stages,
            'unknownStageCount' => collect($stages)->where('requiresMapping', true)->sum('count'),
            'canonicalStages' => self::STAGES,
        ];
    }

    private function resolveStage(string $value, array $stageMapping): ?string
    {
        $key = $this->stageKey($value);
        if (isset($stageMapping[$key])) {
            return $stageMapping[$key];
        }

        return match ($key) {
            'cold_invite', 'cold', 'invite', 'initial', 'initial_outreach', 'first_touch', 'new' => 'cold_invite',
            'follow_up', 'followup', 'follow_up_1', 'follow_up_2', 'reminder' => 'follow_up',
            'after_accept', 'after_accepted', 'accepted', 'onboarding' => 'after_accept',
            'negotiation', 'negotiating', 'terms', 'deal' => 'negotiation',
            'check_in', 'checkin', 're_engagement', 'reengagement' => 'check_in',
            'post_confirmation', 'confirmation', 'confirmed', 'posting_confirmation' => 'post_confirmation',
            default => null,
        };
    }

    private function normalizeStageMapping(array $mapping): array
    {
        $normalized = [];
        foreach ($mapping as $source => $target) {
            $target = $this->stageKey((string) $target);
            if (in_array($target, self::STAGES, true)) {
                $normalized[$this->stageKey((string) $source)] = $target;
            }
        }

        return $normalized;
    }

    private function snapshot(MessageTemplate $template): array
    {
        return [
            'angle_id' => $template->angle_id,
            'platform' => $template->platform,
            'niche' => $template->niche,
            'stage' => $template->stage,
            'copy' => $template->copy,
            'notes' => $template->notes,
            'psychological_trigger' => $template->psychological_trigger,
            'metadata' => (array) ($template->metadata ?? []),
        ];
    }

    private function customFields(array $row, array $mapping): array
    {
        $mapped = array_values($mapping);
        $custom = [];
        foreach ($row as $key => $value) {
            if (Str::startsWith((string) $key, '__') || in_array($key, $mapped, true) || trim((string) $value) === '') {
                continue;
            }
            $custom[(string) $key] = Str::limit((string) $value, 2000, '…');
        }

        return $custom;
    }

    private function appendError(array &$errors, int &$count, array $row, int $rowNumber, string $reason): void
    {
        $count++;
        if (count($errors) >= 200) {
            return;
        }
        $errors[] = [
            'rowNumber' => $rowNumber,
            'reason' => $reason,
            'row' => $this->errorRowPreview((array) ($row['__display'] ?? [])),
        ];
    }

    private function importFields(): array
    {
        return [
            ['key' => 'name', 'label' => 'Template name', 'required' => true],
            ['key' => 'copy', 'label' => 'Message copy', 'required' => true],
            ['key' => 'platform', 'label' => 'Platform', 'required' => false],
            ['key' => 'stage', 'label' => 'Workflow stage', 'required' => false],
            ['key' => 'niche', 'label' => 'Niche', 'required' => false],
            ['key' => 'notes', 'label' => 'Notes', 'required' => false],
            ['key' => 'psychological_trigger', 'label' => 'Psychological trigger', 'required' => false],
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
                throw new CrmImportValidationException('The CSV file is empty. Add a header row and at least one template.');
            }
            $delimiter = $this->detectDelimiter($firstLine);
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter);
            if (! is_array($headers) || $headers === []) {
                throw new CrmImportValidationException('The CSV header row could not be read.');
            }
            $headerMeta = array_map(fn ($header) => ['label' => trim((string) $header), 'key' => $this->normalizeHeader((string) $header)], $headers);
            $normalizedHeaders = array_column($headerMeta, 'key');
            if (array_filter($normalizedHeaders) === []) {
                throw new CrmImportValidationException('The CSV header row is empty.');
            }
            $duplicates = array_keys(array_filter(array_count_values(array_filter($normalizedHeaders)), fn (int $count) => $count > 1));
            if ($duplicates !== []) {
                throw new CrmImportValidationException('Duplicate CSV columns were found after normalization: '.implode(', ', $duplicates).'.');
            }

            $rows = [];
            $displayRows = [];
            $rowNumber = 1;
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;
                if (count($rows) >= 5000) {
                    throw new CrmImportValidationException('Template import limit is 5,000 rows. Split the file and try again.');
                }
                $row = [];
                $display = [];
                foreach ($normalizedHeaders as $index => $header) {
                    if ($header === '') {
                        continue;
                    }
                    $value = trim((string) ($values[$index] ?? ''));
                    $row[$header] = $value;
                    $display[$headerMeta[$index]['label'] ?: $header] = $value;
                }
                if (array_filter($row, fn ($value) => trim((string) $value) !== '') !== []) {
                    $row['__row_number'] = $rowNumber;
                    $row['__display'] = $display;
                    $rows[] = $row;
                    if (count($displayRows) < 5) {
                        $displayRows[] = $display;
                    }
                }
            }
            if ($rows === []) {
                throw new CrmImportValidationException('The CSV contains headers but no template rows.');
            }

            return ['headers' => $headerMeta, 'rows' => $rows, 'displayRows' => $displayRows];
        } finally {
            fclose($handle);
        }
    }

    private function suggestMapping(array $headers): array
    {
        $available = [];
        foreach ($headers as $header) {
            $available[$this->normalizeHeader((string) $header)] = (string) $header;
        }
        $mapping = [];
        foreach (self::FIELD_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (isset($available[$this->normalizeHeader($alias)])) {
                    $mapping[$field] = $available[$this->normalizeHeader($alias)];
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
            if (isset(self::FIELD_ALIASES[(string) $field]) && trim((string) $header) !== '' && $header !== '__skip__') {
                $normalized[(string) $field] = $this->normalizeHeader((string) $header);
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
            $key = $this->normalizeHeader($alias);
            if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return '';
    }

    private function normalizePlatform(string $value): string
    {
        return match (Str::lower(trim($value))) {
            'instagram', 'insta', 'ig', 'instagram_reels' => 'instagram',
            'tiktok', 'tik tok', 'tik_tok', 'tt' => 'tiktok',
            'email', 'e-mail', 'mail' => 'email',
            default => '',
        };
    }

    private function stageKey(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(trim($value))) ?: '', '_');
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = str_replace([' ', '-', '.', '/', '\\'], '_', Str::lower(trim($value)));

        return trim(preg_replace('/_+/', '_', $value) ?: $value, '_');
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
