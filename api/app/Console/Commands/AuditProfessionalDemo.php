<?php

namespace App\Console\Commands;

use App\Services\DemoDatasetAudit;
use Illuminate\Console\Command;

class AuditProfessionalDemo extends Command
{
    protected $signature = 'demo:audit';

    protected $description = 'Validate demo foreign keys, workspace references, lifecycle and inactive integrations';

    public function handle(DemoDatasetAudit $audit): int
    {
        $this->line(json_encode($audit->run(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
