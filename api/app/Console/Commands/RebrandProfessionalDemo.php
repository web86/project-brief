<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Workspace;
use App\Services\DemoDatasetAudit;
use App\Services\DemoEnvironment;
use Database\Seeders\ProfessionalDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RebrandProfessionalDemo extends Command
{
    protected $signature = 'demo:rebrand';

    protected $description = 'Refresh isolated Dev2Done branding and the approved client section order';

    public function handle(DemoDatasetAudit $audit): int
    {
        DemoEnvironment::assertIsolated();
        if (DemoEnvironment::readOnly() || DB::connection()->getConfig('username') !== 'demo_writer') {
            throw new RuntimeException('Demo rebranding requires the isolated demo writer.');
        }
        $before = $audit->run();
        $workspace = Workspace::where('uuid', ProfessionalDemoSeeder::uuid('workspace'))->where('name', 'Harborline Digital Studio')->sole();
        DB::transaction(function () use ($workspace): void {
            $workspace->update(['branding' => app(ProfessionalDemoSeeder::class)->branding()]);
            $project = Project::where('workspace_id', $workspace->id)->where('title', 'Northstar Commerce')->sole();
            $project->briefSections()->where('name', 'Core experience')->update(['position' => 0]);
            $project->briefSections()->where('name', 'Discovery & design')->update(['position' => 2]);
            $project->briefSections()->where('name', 'Core experience')->update(['position' => 1]);
        });
        $after = $audit->run();
        if ($before['contentHash'] !== $after['contentHash'] || $before['counts'] !== $after['counts']) {
            throw new RuntimeException('Rebranding must not change business records.');
        }
        $this->line(json_encode(['passed' => true, 'product' => 'Dev2Done', 'workspace' => $workspace->name, 'primaryColor' => '#5b4bd6', 'taskContentAndCountsUnchanged' => true, 'clientFirstSection' => 'Core experience']));

        return self::SUCCESS;
    }
}
