<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PlatformOverview
{
    public function since(string $period): string
    {
        $hours = ['24h' => 24, '7d' => 168, '30d' => 720][$period];

        return now('UTC')->startOfHour()->subHours($hours - 1)->format('Y-m-d H:i:s');
    }

    public function workspaces(): Builder
    {
        $changes = DB::table('projects')->selectRaw('workspace_id, MAX(updated_at) as activity_at')->groupBy('workspace_id')
            ->unionAll(DB::table('integration_tokens')->selectRaw('workspace_id, MAX(last_used_at) as activity_at')->groupBy('workspace_id'))
            ->unionAll(DB::table('workspace_members')->selectRaw('workspace_id, MAX(updated_at) as activity_at')->groupBy('workspace_id'));
        $activity = DB::query()->fromSub($changes, 'changes')->selectRaw('workspace_id, MAX(activity_at) as last_activity_at')->groupBy('workspace_id');

        return DB::table('workspaces')->leftJoinSub($activity, 'activity', 'activity.workspace_id', '=', 'workspaces.id')
            ->select(['workspaces.id', 'workspaces.uuid', 'workspaces.name', 'workspaces.created_at'])->selectRaw('COALESCE(activity.last_activity_at, workspaces.created_at) as last_activity_at');
    }

    public function owners(array $ids): array
    {
        return DB::table('workspace_members')->join('users', 'users.id', '=', 'workspace_members.user_id')->whereIn('workspace_id', $ids)->where('workspace_members.role', 'owner')
            ->get(['workspace_id', 'users.id', 'users.name', 'users.email', 'workspace_members.status'])->groupBy('workspace_id')->map(fn ($owners) => $owners->map(fn ($owner) => ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'membershipStatus' => $owner->status])->all())->all();
    }

    public function clientCount(?int $workspace = null): int
    {
        $registered = DB::table('workspace_clients')->select('workspace_id')->selectRaw('LOWER(TRIM(email)) as identity')->when($workspace !== null, fn ($q) => $q->where('workspace_id', $workspace));
        $legacy = DB::table('project_clients as c')->join('projects as p', 'p.id', '=', 'c.project_id')->whereNull('p.deleted_at')->when($workspace !== null, fn ($q) => $q->where('p.workspace_id', $workspace));
        $registered->union((clone $legacy)->select('p.workspace_id')->selectRaw('LOWER(TRIM(c.email)) as identity')->whereNotNull('c.email')->whereRaw("TRIM(c.email) != ''"));
        $registered->union((clone $legacy)->select('p.workspace_id', 'c.uuid as identity')->where(fn ($q) => $q->whereNull('c.email')->orWhereRaw("TRIM(c.email) = ''")));

        return DB::query()->fromSub($registered, 'clients')->count();
    }

    public function usage(string $since, ?int $workspace = null, bool $grouped = false): Builder
    {
        $query = DB::table('api_usage_hours')->where('hour', '>=', $since)->where('hour', '<=', now('UTC')->startOfHour())->when($workspace !== null, fn ($q) => $q->where('workspace_id', $workspace));
        if ($grouped) {
            $dimensions = ['dimension_key', 'hour', 'workspace_id', 'integration_source_id', 'integration_token_id', 'method', 'route'];
            $query->select($dimensions)->groupBy($dimensions);
        }

        return $query->selectRaw('COALESCE(SUM(requests), 0) as requests, COALESCE(SUM(responses_2xx), 0) as responses_2xx, COALESCE(SUM(responses_4xx), 0) as responses_4xx, COALESCE(SUM(responses_5xx), 0) as responses_5xx, COALESCE(SUM(responses_429), 0) as responses_429, COALESCE(SUM(duration_us) / NULLIF(SUM(requests), 0) / 1000, 0) as average_ms, COALESCE(MAX(max_duration_us) / 1000.0, 0) as maximum_ms');
    }

    public function webhooks(string $since, ?int $workspace = null): array
    {
        $events = DB::table('webhook_events')->when($workspace !== null, fn ($q) => $q->where('workspace_id', $workspace));
        $deliveries = DB::table('webhook_deliveries as d')->join('webhook_events as e', 'e.id', '=', 'd.webhook_event_id')->when($workspace !== null, fn ($q) => $q->where('e.workspace_id', $workspace));
        $attempts = DB::table('webhook_attempts as a')->join('webhook_deliveries as d', 'd.id', '=', 'a.webhook_delivery_id')->join('webhook_events as e', 'e.id', '=', 'd.webhook_event_id')->when($workspace !== null, fn ($q) => $q->where('e.workspace_id', $workspace))->where('a.created_at', '>=', $since);

        return [
            'events' => $events->where('created_at', '>=', $since)->count(),
            'deliveries' => (clone $deliveries)->where('d.created_at', '>=', $since)->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN d.status = 'succeeded' THEN 1 ELSE 0 END), 0) as succeeded, COALESCE(SUM(CASE WHEN d.status = 'failed' THEN 1 ELSE 0 END), 0) as failed")->first(),
            'backlog' => (clone $deliveries)->whereIn('d.status', ['pending', 'processing'])->count(),
            'attempts' => $attempts->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN a.status = 'succeeded' THEN 1 ELSE 0 END), 0) as succeeded, COALESCE(SUM(CASE WHEN a.status != 'succeeded' THEN 1 ELSE 0 END), 0) as errors, COALESCE(SUM(CASE WHEN a.attempt > 1 THEN 1 ELSE 0 END), 0) as retries")->first(),
        ];
    }
}
