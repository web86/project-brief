<?php

namespace App\Http\Controllers;

use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TaskAgentAssignment;
use App\Services\AccountSecurity;
use App\Services\AgentAccess;
use App\Services\AgentAssignments;
use App\Services\AgentAudit;
use App\Services\AgentCredentials;
use App\Services\AgentPermissions;
use App\Services\AgentRuns;
use App\Services\CurrentWorkspace;
use App\Services\IntegrationTokens;
use App\Services\IntegrationWrites;
use App\Services\Plans\ShadowGuard;
use App\Services\ProjectAccess;
use App\Services\ProjectPermissions;
use App\Services\TaskAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    private function owner(Request $request): int
    {
        $member = app(CurrentWorkspace::class)->resolve($request, DB::transactionLevel() > 0);
        abort_unless($member->role === 'owner', 403);
        if (! $request->isMethodSafe()) {
            app(AccountSecurity::class)->requireFresh($request);
        }

        return $member->workspace_id;
    }

    private function row(int $workspace, string $uuid): ExternalAgent
    {
        return ExternalAgent::where('workspace_id', $workspace)->where('uuid', $uuid)->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->owner($request);
        $rows = ExternalAgent::where('workspace_id', $workspace)->orderBy('id')->get()->map(function ($agent): array {
            $tokens = AgentCredentials::tokens($agent)->whereNull('revoked_at')->orderByDesc('id')->get(['uuid', 'name', 'agent_shared', 'scopes', 'revoked_at', 'last_used_at', 'expires_at']);
            $endpoint = DB::table('webhook_endpoints')->where('external_agent_id', $agent->id)->first();
            $delivery = $endpoint ? DB::table('webhook_deliveries')->where('webhook_endpoint_id', $endpoint->id)->orderByDesc('id')->first() : null;

            return [...$agent->publicState(), 'instructions' => $agent->instructions ?? '', 'permissions' => AgentPermissions::normalize($agent->permissions), 'allowedScopes' => AgentAccess::SCOPES, 'projectCount' => ProjectMember::where('external_agent_id', $agent->id)->count(),
                'projectIds' => Project::where('workspace_id', $agent->workspace_id)->whereIn('id', DB::table('agent_project_grants')->where('external_agent_id', $agent->id)->select('project_id'))->pluck('uuid'),
                'successfulRequests' => (int) $agent->successful_requests, 'failedRequests' => (int) $agent->failed_requests,
                'tokens' => $tokens->map(fn ($t) => ['id' => $t->uuid, 'name' => $t->name, 'shared' => (bool) $t->agent_shared, 'scopes' => json_decode($t->scopes, true), 'revokedAt' => $t->revoked_at, 'lastUsedAt' => $t->last_used_at, 'expiresAt' => $t->expires_at]),
                'webhook' => $endpoint ? ['id' => $endpoint->uuid, 'url' => $endpoint->url, 'enabled' => (bool) $endpoint->enabled, 'status' => $delivery?->status, 'lastError' => $delivery?->error_code] : null];
        });

        return response()->json(['data' => $rows, 'scopes' => AgentAccess::SCOPES, 'permissionDefaults' => AgentPermissions::DEFAULTS, 'scopePermissions' => AgentPermissions::SCOPE_PERMISSIONS, 'credentials' => DB::table('integration_tokens')->where('workspace_id', $workspace)->where('agent_shared', true)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderBy('name')->get()->map(fn ($t) => ['id' => $t->uuid, 'name' => $t->name, 'scopes' => json_decode($t->scopes, true)]), 'projects' => Project::where('workspace_id', $workspace)->orderBy('title')->get()->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->title])])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, ?string $uuid = null): JsonResponse
    {
        $workspace = $this->owner($request);
        AgentAccess::fields($request, ['name', 'description', 'kind', 'active', 'projectIds', 'webhookUrl', 'permissions', 'instructions', 'credentialId']);
        $data = $request->validate(['credentialId' => ['sometimes', 'nullable', 'uuid'], 'instructions' => ['sometimes', 'nullable', 'string', 'max:10000'], 'name' => ['required', 'string', 'max:100', 'regex:/\S/u'], 'description' => ['nullable', 'string', 'max:1000'], 'kind' => ['sometimes', Rule::in(['agent', 'ai', 'automation', 'service'])],
            'active' => ['required', 'boolean'], 'projectIds' => ['present', 'array', 'max:1000'], 'projectIds.*' => ['required', 'uuid', 'distinct'], 'webhookUrl' => ['prohibited'], 'permissions' => ['sometimes', 'array:'.implode(',', array_keys(AgentPermissions::DEFAULTS))], 'permissions.*' => ['required', 'boolean']]);

        return DB::transaction(function () use ($request, $workspace, $uuid, $data): JsonResponse {
            IntegrationWrites::lockWorkspace($workspace);
            $this->owner($request);
            $agent = $uuid ? $this->row($workspace, $uuid) : new ExternalAgent(['workspace_id' => $workspace]);
            $projects = Project::where('workspace_id', $workspace)->whereIn('uuid', $data['projectIds'])->get();
            abort_unless($projects->count() === count($data['projectIds']), 422);
            $previousProjects = DB::table('agent_project_grants')->where('external_agent_id', $agent->id)->pluck('project_id')->all();
            app(ShadowGuard::class)->observe($workspace, 'agent.change', ['agents.active.max' => $data['active'] && (! $agent->exists || ! $agent->active) ? 1 : 0]);
            $agent->fill(['instructions' => array_key_exists('instructions', $data) ? $data['instructions'] : $agent->instructions, 'name' => trim($data['name']), 'description' => $data['description'] ?? null, 'kind' => $uuid ? $agent->kind : 'agent', 'active' => $data['active'], 'permissions' => array_key_exists('permissions', $data) ? AgentPermissions::normalize(array_replace(AgentPermissions::normalize($agent->permissions), $data['permissions'])) : $agent->permissions])->save();
            foreach (Project::withTrashed()->whereIn('id', DB::table('agent_project_grants')->where('external_agent_id', $agent->id)->whereNotIn('project_id', $projects->modelKeys())->select('project_id'))->get() as $project) {
                AgentAssignments::removeProject($request, $agent, $project);
            }
            DB::table('agent_project_grants')->where('external_agent_id', $agent->id)->delete();
            foreach ($projects as $project) {
                DB::table('agent_project_grants')->insert(['external_agent_id' => $agent->id, 'workspace_id' => $workspace, 'project_id' => $project->id]);
                if (! in_array($project->id, $previousProjects, true)) {
                    app(ShadowGuard::class)->observe($project->workspace_id, 'agent.assign');
                    ProjectMember::firstOrCreate(['project_id' => $project->id, 'external_agent_id' => $agent->id], ['workspace_id' => $workspace, 'permissions' => []]);
                }
            }
            if (array_key_exists('credentialId', $data)) {
                AgentCredentials::bind($request, $agent, $data['credentialId']);
            }
            if (! $agent->active) {
                $this->disable($request, $agent);
            }
            app(AccountSecurity::class)->audit($request->user(), 'agent_saved:'.$agent->id);

            return response()->json(['data' => $agent->publicState(), 'webhookSecret' => null], $uuid ? 200 : 201)->header('Cache-Control', 'no-store');
        }, 3);
    }

    private function disable(Request $request, ExternalAgent $agent): void
    {
        DB::table('integration_tokens')->where('external_agent_id', $agent->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        DB::table('agent_credential_links')->where('external_agent_id', $agent->id)->delete();
        $endpoints = DB::table('webhook_endpoints')->where('external_agent_id', $agent->id)->pluck('id');
        DB::table('webhook_endpoints')->whereIn('id', $endpoints)->update(['enabled' => false]);
        DB::table('webhook_deliveries')->whereIn('webhook_endpoint_id', $endpoints)->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'error_code' => 'agent_revoked', 'updated_at' => now()]);
        foreach (TaskAgentAssignment::where('external_agent_id', $agent->id)->whereNull('revoked_at')->get() as $row) {
            if ($row->task) {
                AgentAssignments::revoke($request, $row, 'agent.task.cancelled');
            } else {
                AgentRuns::cancel($row);
                $row->update(['revoked_at' => now(), 'state' => 'cancelled']);
            }
        }
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $workspace = $this->owner($request);
        AgentAccess::fields($request, []);
        DB::transaction(function () use ($request, $workspace, $uuid): void {
            IntegrationWrites::lockWorkspace($workspace);
            $this->owner($request);
            $agent = $this->row($workspace, $uuid);
            $this->disable($request, $agent);
            ProjectMember::where('external_agent_id', $agent->id)->delete();
            DB::table('agent_project_grants')->where('external_agent_id', $agent->id)->delete();
            $agent->update(['active' => false]);
            $agent->delete();
            app(AccountSecurity::class)->audit($request->user(), 'agent_deleted:'.$agent->id);
        }, 3);

        return response()->json(['deleted' => true]);
    }

    public function token(Request $request, string $uuid): JsonResponse
    {
        $workspace = $this->owner($request);
        AgentAccess::fields($request, ['scopes', 'expiresAt', 'replace']);
        $data = $request->validate(['scopes' => ['required', 'array', 'min:1', 'max:'.count(AgentAccess::SCOPES)], 'scopes.*' => ['required', Rule::in(AgentAccess::SCOPES), 'distinct'], 'expiresAt' => ['nullable', 'date', 'after:now', 'before:'.now()->addYears(2)->toDateString()], 'replace' => ['sometimes', 'boolean']]);
        if (! empty($data['expiresAt'])) {
            $data['expiresAt'] = Carbon::parse($data['expiresAt'])->utc();
        }

        return DB::transaction(function () use ($request, $workspace, $uuid, $data): JsonResponse {
            IntegrationWrites::lockWorkspace($workspace);
            $this->owner($request);
            $agent = $this->row($workspace, $uuid);
            abort_unless($agent->active, 422);
            abort_if(array_diff($data['scopes'], AgentAccess::SCOPES), 422);
            app(ShadowGuard::class)->observe($workspace, 'agent.key');
            if ($data['replace'] ?? false) {
                DB::table('integration_tokens')->where('external_agent_id', $agent->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            $token = app(IntegrationTokens::class)->create($workspace, [...$data, 'name' => $agent->name, 'agentId' => $agent->id]);
            app(AccountSecurity::class)->audit($request->user(), 'agent_token_created:'.$agent->id);

            return response()->json($token, 201)->header('Cache-Control', 'no-store');
        }, 3);
    }

    public function revokeToken(Request $request, string $uuid, string $credential): JsonResponse
    {
        $workspace = $this->owner($request);
        AgentAccess::fields($request, []);
        DB::transaction(function () use ($request, $workspace, $uuid, $credential): void {
            IntegrationWrites::lockWorkspace($workspace);
            $this->owner($request);
            $agent = $this->row($workspace, $uuid);
            $token = DB::table('integration_tokens')->where('external_agent_id', $agent->id)->where('uuid', $credential)->firstOrFail();
            DB::table('integration_tokens')->where('id', $token->id)->update(['revoked_at' => now()]);
            AgentCredentials::cancelUnavailableDeliveries($agent);
            app(AccountSecurity::class)->audit($request->user(), 'agent_token_revoked:'.$agent->id);
        }, 3);

        return response()->json(['revoked' => true]);
    }

    public function team(Request $request, Project $project, string $uuid): JsonResponse
    {
        AgentAccess::fields($request, []);
        DB::transaction(function () use ($request, $project, $uuid): void {
            IntegrationWrites::lockWorkspace($project->workspace_id);
            ProjectAccess::project($request, $project->uuid);
            ProjectPermissions::authorize($request, $project, 'manage_team', true);
            app(AccountSecurity::class)->requireFresh($request);
            $agent = $this->row($project->workspace_id, $uuid);
            if ($request->isMethod('DELETE')) {
                AgentAssignments::removeProject($request, $agent, $project);
            } else {
                abort_unless($agent->active && DB::table('agent_project_grants')->where('workspace_id', $project->workspace_id)->where('external_agent_id', $agent->id)->where('project_id', $project->id)->exists(), 422);
                app(ShadowGuard::class)->observe($project->workspace_id, 'agent.assign');
                ProjectMember::firstOrCreate(['project_id' => $project->id, 'external_agent_id' => $agent->id], ['workspace_id' => $project->workspace_id, 'permissions' => []]);
            }
            app(AccountSecurity::class)->audit($request->user(), 'agent_project_assignment:'.$agent->id);
        }, 3);

        return response()->json(['saved' => true]);
    }

    public function task(Request $request, string $task): JsonResponse
    {
        AgentAccess::fields($request, ['agentId', 'instruction', 'deferRun', 'replace']);
        $data = $request->validate(['deferRun' => ['sometimes', 'boolean'], 'replace' => ['sometimes', 'boolean'], 'agentId' => ['required', 'uuid'], 'instruction' => ['sometimes', 'nullable', 'string', 'max:5000']]);
        $project = TaskAccess::task($request, $task)->project;
        DB::transaction(function () use ($request, $task, $project, $data): void {
            IntegrationWrites::lockWorkspace($project->workspace_id);
            $model = TaskAccess::task($request, $task, true);
            ProjectPermissions::authorize($request, $project, 'manage_structure', true);
            $agent = $this->row($project->workspace_id, $data['agentId']);
            $existing = TaskAgentAssignment::where('task_id', $model->id)->where('external_agent_id', $agent->id)->whereNull('revoked_at')->first();
            if ($request->isMethod('DELETE')) {
                if ($existing) {
                    AgentAssignments::revoke($request, $existing);
                }
            } else {
                abort_unless($project->status === 'active' && $agent->active && ProjectMember::where('project_id', $project->id)->where('external_agent_id', $agent->id)->where('workspace_id', $project->workspace_id)->exists()
                    && DB::table('agent_project_grants')->where('external_agent_id', $agent->id)->where('project_id', $project->id)->where('workspace_id', $project->workspace_id)->exists(), 422);
                app(ShadowGuard::class)->observe($project->workspace_id, 'agent.assign');
                if ($data['replace'] ?? false) {
                    foreach (TaskAgentAssignment::where('task_id', $model->id)->where('external_agent_id', '!=', $agent->id)->whereNull('revoked_at')->get() as $previous) {
                        AgentAssignments::revoke($request, $previous);
                    }
                }
                if (! $existing) {
                    $row = TaskAgentAssignment::create(['workspace_id' => $project->workspace_id, 'task_id' => $model->id, 'external_agent_id' => $agent->id]);
                    if (! ($data['deferRun'] ?? false)) {
                        AgentRuns::request($request, $row, trim($data['instruction'] ?? ''), 'initial-'.$row->uuid, false);
                    }
                    AgentAssignments::event($row, 'agent.task.assigned');
                    AgentAudit::task($request, $row, 'agent_assigned');
                }
            }
        }, 3);

        return response()->json(['saved' => true]);
    }
}
