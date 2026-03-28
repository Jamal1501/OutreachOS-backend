<?php

namespace App\Console\Commands;

use App\Services\OperationalDataImportService;
use Illuminate\Console\Command;

class ImportGoogleSheetToOperationalDb extends Command
{
    protected $signature = 'outreach:import-sheet
                            {sheetId? : Google Sheets workbook ID}
                            {--project= : Override project name}
                            {--truncate : Delete imported operational rows for this project before re-importing}';

    protected $description = 'Import the current Google Sheets operational workbook into the relational Laravel database';

    public function handle(OperationalDataImportService $importer): int
    {
        $sheetId = trim((string) ($this->argument('sheetId') ?: config('services.google.default_sheet_id')));

        if ($sheetId === '') {
            $this->error('Missing sheetId and GOOGLE_DEFAULT_SHEET_ID');
            return self::FAILURE;
        }

        $summary = $importer->importWorkbook(
            $sheetId,
            $this->option('project') ?: null,
            (bool) $this->option('truncate'),
        );

        $this->info('Operational workbook imported.');
        $this->line('Project: ' . $summary['project_name'] . ' (#' . $summary['project_id'] . ')');
        $this->line('Creators: ' . json_encode($summary['creators']));
        $this->line('Message templates: ' . json_encode($summary['message_templates']));
        $this->line('Tasks: ' . json_encode($summary['tasks']));
        $this->line('Outreach events: ' . json_encode($summary['outreach_events']));
        $this->line('Discovery: ' . json_encode($summary['discovery']));
        $this->line('Enrichment snapshots: ' . json_encode($summary['enrichment_snapshots']));

        return self::SUCCESS;
    }
}
