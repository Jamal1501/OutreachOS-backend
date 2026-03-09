<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Illuminate\Support\Arr;
use RuntimeException;

class GoogleSheetsService
{
    public function getHeaders(string $sheetId, string $sheetName): array
    {
        $rows = $this->getValues($sheetId, "{$sheetName}!1:1");

        return array_values(array_filter($rows[0] ?? [], fn ($value) => $value !== null && $value !== ''));
    }

    public function getRows(string $sheetId, string $sheetName): array
    {
        $rows = $this->getValues($sheetId, "{$sheetName}!A:ZZ");

        if (count($rows) === 0) {
            return [];
        }

        $headers = $rows[0];
        $records = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            $assoc = [];

            foreach ($headers as $headerIndex => $header) {
                if ($header === null || $header === '') {
                    continue;
                }

                $assoc[(string) $header] = $row[$headerIndex] ?? '';
            }

            $assoc['_row_number'] = $index + 2;
            $records[] = $assoc;
        }

        return $records;
    }

public function appendRows(string $sheetId, string $sheetName, array $rows): int
{
    if (count($rows) === 0) {
        return 0;
    }

    $cleanRows = array_map(function ($row) {
        return array_map(function ($value) {
            return $this->sanitizeCellValue($value);
        }, $row);
    }, $rows);

    $body = new ValueRange(['values' => $cleanRows]);

    $this->service()->spreadsheets_values->append(
        $sheetId,
        "{$sheetName}!A1",
        $body,
        ['valueInputOption' => 'USER_ENTERED']
    );

    return count($cleanRows);
}

    public function appendAssocRows(string $sheetId, string $sheetName, array $records, ?array $headers = null): int
    {
        if (count($records) === 0) {
            return 0;
        }

        $headers ??= $this->getHeaders($sheetId, $sheetName);
        $rows = [];

        foreach ($records as $record) {
            $rows[] = $this->recordToRow($record, $headers);
        }

        return $this->appendRows($sheetId, $sheetName, $rows);
    }

    public function updateAssocRow(string $sheetId, string $sheetName, int $rowNumber, array $record, ?array $headers = null): void
    {
        $headers ??= $this->getHeaders($sheetId, $sheetName);
        $row = $this->recordToRow($record, $headers);

        $this->updateRow($sheetId, $sheetName, $rowNumber, $row);
    }

    // app/Services/GoogleSheetsService.php
public function batchUpdateAssocRows(string $sheetId, string $sheetName, array $updates, ?array $headers = null): int
{
    if (count($updates) === 0) {
        return 0;
    }

    $headers ??= $this->getHeaders($sheetId, $sheetName);
    $data = [];

    foreach ($updates as $update) {
        $rowNumber = (int) ($update['rowNumber'] ?? 0);
        $record = $update['record'] ?? null;

        if ($rowNumber <= 0 || !is_array($record)) {
            continue;
        }

        $row = $this->recordToRow($record, $headers);
        $lastColumn = $this->columnLetter(count($row));

        $data[] = new ValueRange([
            'range' => "{$sheetName}!A{$rowNumber}:{$lastColumn}{$rowNumber}",
            'values' => [$row],
        ]);
    }

    if (count($data) === 0) {
        return 0;
    }

    $this->service()->spreadsheets_values->batchUpdate(
        $sheetId,
        new \Google\Service\Sheets\BatchUpdateValuesRequest([
            'valueInputOption' => 'USER_ENTERED',
            'data' => $data,
        ])
    );

    return count($data);
}


    public function clearAssocRow(string $sheetId, string $sheetName, int $rowNumber, ?array $headers = null): void
    {
        $headers ??= $this->getHeaders($sheetId, $sheetName);
        $row = array_fill(0, count($headers), '');

        $this->updateRow($sheetId, $sheetName, $rowNumber, $row);
    }

    public function updateRow(string $sheetId, string $sheetName, int $rowNumber, array $row): void
    {
        $body = new ValueRange(['values' => [$row]]);
        $lastColumn = $this->columnLetter(count($row));

        $this->service()->spreadsheets_values->update(
            $sheetId,
            "{$sheetName}!A{$rowNumber}:{$lastColumn}{$rowNumber}",
            $body,
            ['valueInputOption' => 'USER_ENTERED']
        );
    }

    public function findFirstRowBy(string $sheetId, string $sheetName, string $column, string $value): ?array
    {
        $value = trim($value);

        foreach ($this->getRows($sheetId, $sheetName) as $record) {
            if (trim((string) ($record[$column] ?? '')) === $value) {
                return $record;
            }
        }

        return null;
    }

    private function getValues(string $sheetId, string $range): array
    {
        $response = $this->service()->spreadsheets_values->get($sheetId, $range);

        return $response->getValues() ?? [];
    }

private function recordToRow(array $record, array $headers): array
{
    $row = [];

    foreach ($headers as $header) {
        $row[] = $this->sanitizeCellValue(Arr::get($record, $header, ''));
    }

    return $row;
}

    private function service(): Sheets
    {
        $client = new GoogleClient();
        $client->setAuthConfig($this->serviceAccountConfig());
        $client->addScope(Sheets::SPREADSHEETS);

        return new Sheets($client);
    }

    private function serviceAccountConfig(): array
    {
        $raw = (string) config('services.google.service_account_json');

        if ($raw === '') {
            throw new RuntimeException('Missing Google service account config');
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $decodedBase64 = json_decode(base64_decode($raw, true) ?: '', true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBase64)) {
            return $decodedBase64;
        }

        throw new RuntimeException('Invalid Google service account JSON');
    }

    private function columnLetter(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)) . $letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }
    private function sanitizeCellValue(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? 'TRUE' : 'FALSE';
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return $json === false ? '' : $json;
}
}
