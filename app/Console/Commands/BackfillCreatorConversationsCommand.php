<?php

namespace App\Console\Commands;

use App\Services\CreatorRelationshipTimelineService;
use Illuminate\Console\Command;

class BackfillCreatorConversationsCommand extends Command
{
    protected $signature = 'outreach:backfill-conversations {--projectId= : Optional project ID} {--limit=1000 : Max events to inspect}';

    protected $description = 'Attach existing outreach events and saved message drafts to creator conversation history.';

    public function handle(CreatorRelationshipTimelineService $timeline): int
    {
        $result = $timeline->backfillConversationLinks(
            projectId: (string) ($this->option('projectId') ?: ''),
            limit: (int) ($this->option('limit') ?: 1000),
        );

        $this->info(sprintf(
            'Processed %d events. Linked %d to creator profiles. Snapshotted %d message drafts.',
            $result['processed'],
            $result['linked'],
            $result['snapshotted'],
        ));

        return self::SUCCESS;
    }
}
