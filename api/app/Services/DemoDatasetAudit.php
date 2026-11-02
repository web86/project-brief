<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DemoDatasetAudit
{
    public function run(): array
    {
        DemoEnvironment::assertIsolated();
        $errors = [];
        $tables = Schema::getTableListing(DB::getDriverName() === 'mysql' ? DB::getDatabaseName() : null);
        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $query = DB::table($table.' as child')->leftJoin($foreign['foreign_table'].' as parent', function ($join) use ($foreign): void {
                    foreach ($foreign['columns'] as $index => $column) {
                        $join->on('child.'.$column, '=', 'parent.'.$foreign['foreign_columns'][$index]);
                    }
                });
                foreach ($foreign['columns'] as $column) {
                    $query->whereNotNull('child.'.$column);
                }
                if ($query->whereNull('parent.'.$foreign['foreign_columns'][0])->exists()) {
                    $errors[] = 'Orphan: '.$table.'.'.$foreign['name'];
                }
            }
        }
        foreach ([['tasks', 'project_section_id', 'project_sections', 'project_id'], ['comments', 'task_id', 'tasks', 'project_id'], ['project_members', 'project_id', 'projects', 'workspace_id'], ['project_clients', 'workspace_client_id', 'workspace_clients', null], ['task_agent_assignments', 'external_agent_id', 'external_agents', 'workspace_id']] as [$child, $key, $parent, $scope]) {
            if ($scope && DB::table($child.' as c')->join($parent.' as p', 'p.id', '=', 'c.'.$key)->whereNotNull('c.'.$scope)->whereColumn('c.'.$scope, '!=', 'p.'.$scope)->exists()) {
                $errors[] = 'Cross-scope reference: '.$child;
            }
        }
        if (DB::table('task_agent_assignments as a')->join('tasks as t', 't.id', '=', 'a.task_id')->join('projects as p', 'p.id', '=', 't.project_id')->whereColumn('a.workspace_id', '!=', 'p.workspace_id')->exists()) {
            $errors[] = 'Cross-workspace task assignment';
        }
        if (DB::table('project_clients as c')->join('projects as p', 'p.id', '=', 'c.project_id')->join('workspace_clients as w', 'w.id', '=', 'c.workspace_client_id')->whereColumn('w.workspace_id', '!=', 'p.workspace_id')->exists()) {
            $errors[] = 'Cross-workspace client';
        }
        $counts = [];
        foreach (['workspaces', 'users', 'projects', 'project_clients', 'tasks', 'task_history', 'comments', 'attachments', 'project_requests', 'project_feedback', 'external_agents', 'agent_runs', 'provisioning_operations'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        foreach (['projects' => 10, 'tasks' => 150, 'users' => 8, 'project_requests' => 14, 'project_feedback' => 8, 'external_agents' => 3] as $table => $expected) {
            if ($counts[$table] !== $expected) {
                $errors[] = 'Unexpected count: '.$table;
            }
        }
        if (DB::table('tasks')->whereNotIn('status', array_keys(TaskAudit::STATUSES))->exists()
            || DB::table('tasks')->whereIn('status', ['approved', 'in_progress', 'review', 'done'])->where('client_approved', false)->exists()) {
            $errors[] = 'Invalid task lifecycle';
        }
        if (DB::table('integration_tokens')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()
            || DB::table('webhook_endpoints')->where('enabled', true)->exists() || DB::table('webhook_deliveries')->whereIn('status', ['pending', 'processing'])->exists()
            || DB::table('users')->where('platform_admin', true)->exists()) {
            $errors[] = 'Active external capability or platform authority';
        }
        if (DB::table('tasks')->whereColumn('client_approved_at', '<', 'created_at')->exists()
            || DB::table('task_history as h')->join('tasks as t', 't.id', '=', 'h.task_id')->whereColumn('h.created_at', '<', 't.created_at')->exists()) {
            $errors[] = 'History or approval predates task creation';
        }
        foreach (DB::table('project_requests')->where('status', 'approved')->get() as $request) {
            $result = json_decode($request->result, true);
            if (! isset($result['operationId'], $result['projectId']) || ! DB::table('project_provisionings')->where('uuid', $result['operationId'])->where('workspace_id', $request->workspace_id)->exists()) {
                $errors[] = 'Approved request has no matching provisioning';
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(implode('; ', $errors));
        }
        $content = DB::table('tasks')->orderBy('uuid')->get(['uuid', 'title', 'description', 'expected_result', 'developer_notes', 'status', 'priority', 'estimate_hours', 'price', 'created_at'])->toJson();

        $snapshot = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row))->all();
            sort($rows);
            $snapshot[$table] = hash('sha256', implode('\n', $rows));
        }
        ksort($snapshot);

        return ['databaseHash' => hash('sha256', json_encode($snapshot)), 'counts' => $counts, 'orphans' => 0, 'crossScopeReferences' => 0, 'contentHash' => hash('sha256', $content)];
    }
}
