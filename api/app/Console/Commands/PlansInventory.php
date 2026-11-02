<?php

namespace App\Console\Commands;

use App\Services\Plans\LegacyInventory;
use App\Services\Plans\WorkspaceUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlansInventory extends Command
{
    protected $signature = 'plans:inventory {--dry-run : Read only; no bootstrap or business writes} {--apply : Explicit operator backfill} {--extend-cohort : Include workspaces created during the shadow phase} {--refresh : Recheck completed workspace accounting} {--after=0 : Exclusive workspace ID cursor} {--limit=100 : Maximum workspaces, 1..1000} {--seconds=30 : Maximum wall time between chunks, 1..300}';

    protected $description = 'Bounded legacy compatibility inventory and accounting; Observe only';

    public function handle(LegacyInventory $inventory, WorkspaceUsage $usage): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply === (bool) $this->option('dry-run') || (! $apply && ($this->option('extend-cohort') || $this->option('refresh')))) {
            $this->error('Choose exactly one of --dry-run or --apply. Cohort extension/refresh require --apply.');

            return self::FAILURE;
        }
        foreach (['after' => [0, PHP_INT_MAX], 'limit' => [1, 1000], 'seconds' => [1, 300]] as $key => [$min, $max]) {
            if (! ctype_digit((string) $this->option($key)) || (float) $this->option($key) < $min || (float) $this->option($key) > $max) {
                $this->error('Invalid bounded '.$key.'.');

                return self::FAILURE;
            }
        }
        $cohort = $apply ? $inventory->bootstrap((bool) $this->option('extend-cohort')) : DB::table('plan_inventory_cohorts')->where('id', 1)->first();
        $cutoff = $cohort?->workspace_cutoff_id ?? (int) DB::table('workspaces')->max('id');
        $after = (int) $this->option('after');
        $deadline = microtime(true) + (int) $this->option('seconds');
        $report = ['mode' => $apply ? 'apply_observe' : 'dry_run', 'cutoff_id' => (string) $cutoff, 'processed' => 0, 'skipped' => 0, 'needs_review' => 0, 'failed' => 0, 'pending' => 0, 'uninitialized' => 0, 'new_mappings' => 0, 'next_after' => (string) $after];
        $ids = DB::table('workspaces')->where('id', '>', $after)->where('id', '<=', $cutoff)->orderBy('id')->limit((int) $this->option('limit'))->pluck('id');
        foreach ($ids as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            if (! $apply) {
                $measurements = $usage->snapshot($id);
                $ready = collect($measurements)->only(['projects.active.max', 'clients.max', 'developers.max', 'agents.active.max'])->every(fn ($m) => $m['state'] === 'ready');
                $report[$ready ? 'processed' : 'needs_review']++;
                $report['uninitialized'] += DB::table('workspace_subscriptions')->where('workspace_id', $id)->exists() ? 0 : 1;
            } else {
                try {
                    $refresh = (bool) $this->option('refresh');
                    do {
                        $result = $inventory->process($id, refresh: $refresh);
                        $report['new_mappings'] += $result['new_mappings'];
                        $refresh = false;
                    } while ($result['state'] === 'pending' && microtime(true) < $deadline);
                    $report[$result['state']]++;
                    if ($result['state'] === 'pending') {
                        // Keep the workspace in the next keyset page; its client checkpoint resumes independently.
                        break;
                    }
                } catch (Throwable $e) {
                    $inventory->failed($id);
                    $report['failed']++;
                    $this->warn('Workspace ID '.$id.': inventory_operation_failed; retry with the same cursor.');
                    break;
                }
            }
            $report['next_after'] = (string) $id;
        }
        $report['has_more'] = DB::table('workspaces')->where('id', '>', $report['next_after'])->where('id', '<=', $cutoff)->exists();
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $report['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
