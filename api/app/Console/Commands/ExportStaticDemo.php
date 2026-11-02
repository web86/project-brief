<?php

namespace App\Console\Commands;

use App\Services\DemoStaticSnapshotExport;
use Illuminate\Console\Command;

class ExportStaticDemo extends Command
{
    protected $signature = 'demo:export-static';

    protected $description = 'Export allowlisted read contracts from the isolated synthetic demo using SELECT-only credentials';

    public function handle(DemoStaticSnapshotExport $export): int
    {
        $this->line(json_encode($export->run(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
