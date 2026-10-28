<?php

namespace App\Console\Commands;

use App\Services\ProjectLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupProjectFiles extends Command
{
    protected $signature = 'projects:cleanup-files';

    protected $description = 'Retry physical file deletion after permanent project removal';

    public function handle(ProjectLifecycle $lifecycle): int
    {
        DB::table('project_sync_events')->where('created_at', '<', now()->subDays(7))->delete();
        if ($lifecycle->cleanupFiles() > 0) {
            $this->error('Project file cleanup incomplete; retry required.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
