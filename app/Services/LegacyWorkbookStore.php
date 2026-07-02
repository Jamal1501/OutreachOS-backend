<?php

namespace App\Services;

use Illuminate\Support\Arr;

/**
 * External workbook runtime access has been intentionally disabled.
 *
 * The app is now database/workspace-first. This class remains only as a
 * compatibility shim so older controller/service method signatures keep
 * working while all runtime reads/writes use the application database.
 */
class LegacyWorkbookStore
{
    public function getHeaders(string $sheetId, string $sheetName): array
    {
        return [];
    }

    public function getRows(string $sheetId, string $sheetName): array
    {
        return [];
    }

    public function appendRows(string $sheetId, string $sheetName, array $rows): int
    {
        // Return the intended count so legacy import endpoints do not throw,
        // but do not write to external workbook stores.
        return count($rows);
    }

    public function appendAssocRows(string $sheetId, string $sheetName, array $records, ?array $headers = null): int
    {
        return count($records);
    }

    public function updateAssocRow(string $sheetId, string $sheetName, int $rowNumber, array $record, ?array $headers = null): void
    {
        // no-op
    }

    public function batchUpdateAssocRows(string $sheetId, string $sheetName, array $updates, ?array $headers = null): int
    {
        return count(array_filter($updates, fn ($update) => is_array($update) && ((int) ($update['rowNumber'] ?? 0)) > 0));
    }

    public function clearAssocRow(string $sheetId, string $sheetName, int $rowNumber, ?array $headers = null): void
    {
        // no-op
    }

    public function updateRow(string $sheetId, string $sheetName, int $rowNumber, array $row): void
    {
        // no-op
    }

    public function findFirstRowBy(string $sheetId, string $sheetName, string $column, string $value): ?array
    {
        return null;
    }

    public function recordToRowForTesting(array $record, array $headers): array
    {
        return array_map(fn ($header) => (string) Arr::get($record, $header, ''), $headers);
    }
}
