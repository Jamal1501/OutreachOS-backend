<?php

namespace App\Services;

use Illuminate\Support\Str;

class OutreachLogService
{
    public function __construct(
        private GoogleSheetsService $sheets,
        private OperationalMirrorService $mirror,
    ) {
    }

    public function appendEvent(string $sheetId, array $payload): string
    {
        $eventIds = $this->appendEvents($sheetId, [$payload]);

        return $eventIds[0] ?? (string) Str::uuid();
    }

    public function appendEvents(string $sheetId, array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        $headers = $this->sheets->getHeaders($sheetId, 'Outreach_Log');
        $records = [];
        $eventIds = [];

        foreach ($payloads as $payload) {
            $eventId = (string) ($payload['Event_ID'] ?? Str::uuid());
            $eventIds[] = $eventId;

            $records[] = [
                'Event_ID' => $eventId,
                'Platform' => (string) ($payload['Platform'] ?? ''),
                'Handle' => (string) ($payload['Handle'] ?? ''),
                'Channel' => (string) ($payload['Channel'] ?? ''),
                'Event_Type' => (string) ($payload['Event_Type'] ?? ''),
                'Template_ID' => (string) ($payload['Template_ID'] ?? ''),
                'Sender_Account' => (string) ($payload['Sender_Account'] ?? ''),
                'Sent_At' => (string) ($payload['Sent_At'] ?? now()->toDateTimeString()),
                'Status' => (string) ($payload['Status'] ?? ''),
                'URL' => (string) ($payload['URL'] ?? ''),
                'Notes' => (string) ($payload['Notes'] ?? ''),
            ];
        }

        $this->sheets->appendAssocRows($sheetId, 'Outreach_Log', $records, $headers);
        $this->mirror->syncOutreachEvents($sheetId, $eventIds);

        return $eventIds;
    }
}
