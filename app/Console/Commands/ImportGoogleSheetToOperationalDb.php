<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportGoogleSheetToOperationalDb extends Command
{
    protected $signature = 'outreach:import-sheet {sheetId? : Disabled legacy external workbook ID}';

    protected $description = 'Disabled legacy command. External spreadsheet runtime access has been removed.';

    public function handle(): int
    {
        $this->warn('Legacy spreadsheet runtime access is disabled. Use database/workspace data paths instead.');

        return self::SUCCESS;
    }
}
