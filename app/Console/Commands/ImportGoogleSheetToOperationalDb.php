<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportGoogleSheetToOperationalDb extends Command
{
    protected $signature = 'outreach:import-sheet {sheetId? : Disabled legacy Google Sheets workbook ID}';

    protected $description = 'Disabled legacy command. Google Sheets runtime access has been removed.';

    public function handle(): int
    {
        $this->warn('Google Sheets runtime access is disabled. Use database/workspace data paths instead.');

        return self::SUCCESS;
    }
}
