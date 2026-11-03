<?php

namespace App\Console\Commands;

use App\Services\IntegrationWrites;
use App\Services\Plans\SubscriptionLifecycle;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReconcileSubscriptions extends Command
{
    protected $signature = 'plans:reconcile-subscriptions {--apply} {--after=0} {--limit=20} {--jobs=50}';

    protected $description = 'Inspect or reconcile a bounded batch of commercial subscriptions and stale execution receipts';

    public function handle(SubscriptionLifecycle $lifecycle): int
    {
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $jobLimit = filter_var($this->option('jobs'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($after === false || $limit === false || $jobLimit === false) {
            $this->error('Invalid reconciliation bounds.');

            return self::INVALID;
        }
        $ids = DB::table('workspace_subscriptions as subscription')->where('workspace_id', '>', $after)
            ->where(fn ($query) => $query->where('enforcement_mode', 'enforce')->orWhereExists(
                DB::table('workspace_execution_epochs as epoch')->whereColumn('epoch.workspace_id', 'subscription.workspace_id')
            ))->orderBy('workspace_id')->limit($limit + 1)->pluck('workspace_id');
        $page = $ids->take($limit);
        $apply = (bool) $this->option('apply');
        $counts = ['agent' => 0, 'webhook' => 0];
        foreach ($page as $workspaceId) {
            if ($apply) {
                $lifecycle->catchUp($workspaceId);
            }
            $result = DB::transaction(function () use ($workspaceId, $jobLimit, $apply): array {
                if ($apply) {
                    IntegrationWrites::lockWorkspace($workspaceId);
                }

                return [
                    'agent' => $this->reconcile(
                        DB::table('agent_runs')->whereIn('task_agent_assignment_id', DB::table('task_agent_assignments')->where('workspace_id', $workspaceId)->select('id'))
                            ->whereIn('state', ['queued', 'running']),
                        'agent', $workspaceId, $jobLimit, $apply,
                        ['state' => 'cancelled', 'completed_at' => now('UTC')],
                    ),
                    'webhook' => $this->reconcile(
                        DB::table('webhook_deliveries')->whereIn('webhook_endpoint_id', DB::table('webhook_endpoints')->where('workspace_id', $workspaceId)->select('id'))
                            ->whereIn('status', ['pending', 'processing']),
                        'webhook', $workspaceId, $jobLimit, $apply,
                        ['status' => 'cancelled'],
                    ),
                ];
            }, 3);
            foreach ($result as $group => $count) {
                $counts[$group] += $count;
            }
        }
        $this->line(json_encode(['apply' => $apply, 'workspaces' => $page->count(), 'stale' => $counts, 'nextAfter' => $page->last() ?? $after, 'hasMore' => $ids->count() > $limit], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function reconcile(Builder $jobs, string $group, int $workspaceId, int $limit, bool $apply, array $updates): int
    {
        $epoch = DB::table('workspace_execution_epochs')->where('workspace_id', $workspaceId)->where('feature_group', $group)->first();
        if (! $epoch) {
            return 0;
        }
        $ids = $jobs->where(function (Builder $query) use ($epoch, $group): void {
            $query->whereNull('execution_epoch')->orWhere('execution_epoch', '<>', $epoch->epoch)
                ->orWhereNull('execution_feature_group')->orWhere('execution_feature_group', '<>', $group);
        })->orderBy('id')->limit($limit)->pluck('id');
        if ($apply && $ids->isNotEmpty()) {
            return DB::table($jobs->from)->whereIn('id', $ids)->update($updates + ['error_code' => 'subscription_execution_stale', 'updated_at' => now('UTC')]);
        }

        return $ids->count();
    }
}
