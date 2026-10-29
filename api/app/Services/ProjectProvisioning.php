<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProjectProvisioning
{
    /** IntegrationWrites holds the workspace lock and transaction. */
    public function create(object $token, array $input): array
    {
        $externalHash = hash('sha256', $input['externalId']);
        $bodyHash = IntegrationWrites::fingerprint($input);
        $existing = DB::table('project_provisionings')->where('workspace_id', $token->workspace_id)
            ->where('integration_token_id', $token->id)->where('external_hash', $externalHash)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->body_hash, $bodyHash), 409);

            return [['data' => $this->resource($existing, false)], 200];
        }
        $result = $this->createNew($token->workspace_id, $token->id, $input, $externalHash);
        app(WebhookEvents::class)->provisioned(Project::where('uuid', $result['projectId'])->firstOrFail(), $result, $input['externalId']);

        return [['data' => $result], 201];
    }

    /** The request decision holds the same workspace lock as direct provisioning. */
    public function createForRequest(ProjectRequest $request, bool $sendInvitation): array
    {
        return $this->createNew($request->workspace_id, $request->integration_token_id, [
            'client' => ['name' => $request->client_name, 'email' => $request->email, 'company' => $request->company, 'website' => $request->website],
            'project' => ['name' => $request->project_name, 'description' => $request->description, 'language' => $request->language],
            'sendInvitation' => $sendInvitation,
        ], hash('sha256', random_bytes(32)));
    }

    private function createNew(int $workspaceId, int $tokenId, array $input, string $externalHash): array
    {
        $identity = app(ClientIdentities::class)->resolve($workspaceId, $input['client']);
        $project = app(ProjectCreation::class)->create($workspaceId, [
            'title' => $input['project']['name'], 'description' => $input['project']['description'], 'language' => $input['project']['language'],
        ], [], emitEvent: false);
        $client = $project->clients()->create([
            'workspace_client_id' => $identity->id, 'name' => $identity->name, 'email' => $identity->email,
            'preferred_locale' => SupportedLocales::storage($input['project']['language']),
        ]);
        $id = DB::table('project_provisionings')->insertGetId([
            'uuid' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'integration_token_id' => $tokenId,
            'external_hash' => $externalHash, 'body_hash' => IntegrationWrites::fingerprint($input), 'workspace_client_id' => $identity->id,
            'project_id' => $project->id, 'project_client_id' => $client->id,
            'invitation_status' => $input['sendInvitation'] ? 'pending' : 'not_requested', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = $this->resource(DB::table('project_provisionings')->find($id), true);

        return $result;
    }

    private function resource(object $row, bool $created): array
    {
        $project = Project::where('workspace_id', $row->workspace_id)->find($row->project_id);
        abort_unless($project, 410);
        $client = $project->clients()->find($row->project_client_id);
        $identity = DB::table('workspace_clients')->where('workspace_id', $row->workspace_id)->find($row->workspace_client_id);
        abort_unless($client && $identity, 410);

        return ['operationId' => $row->uuid, 'projectId' => $project->uuid, 'clientId' => $identity->uuid,
            'projectClientId' => $client->uuid, 'projectUrl' => self::url($project), 'invitation' => $row->invitation_status, 'created' => $created];
    }

    public static function url(Project $project): string
    {
        return rtrim(config('projectbrief.frontend_url'), '/').'/project/'.$project->uuid;
    }

    public function status(string $uuid, int $workspaceId): string
    {
        return DB::table('project_provisionings')->where('workspace_id', $workspaceId)->where('uuid', $uuid)->value('invitation_status');
    }

    public function deliver(string $uuid, int $workspaceId): void
    {
        $delivery = DB::transaction(function () use ($uuid, $workspaceId): ?array {
            IntegrationWrites::lockWorkspace($workspaceId);
            $row = DB::table('project_provisionings')->where('workspace_id', $workspaceId)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($row->invitation_status !== 'pending') {
                return null;
            }
            $project = Project::where('workspace_id', $workspaceId)->find($row->project_id);
            $client = $project?->clients()->find($row->project_client_id);
            if (! $project || ! $client?->active || $project->status !== 'active') {
                $this->transition($row, $project, 'failed');

                return null;
            }
            $link = app(ClientInvitations::class)->issue($project, $client, now()->addDays(7));
            $this->transition($row, $project, 'sending');

            return [$row, $project, $client, $link];
        }, 3);
        if (! $delivery) {
            return;
        }
        [$row, $project, $client, $link] = $delivery;
        try {
            $sent = app(ClientInvitations::class)->deliver($project, $client, $link['url']);
        } catch (Throwable $exception) {
            app(SafeExceptionReporter::class)->report($exception);
            $sent = false;
        }
        DB::transaction(function () use ($workspaceId, $row, $project, $client, $link, $sent): void {
            IntegrationWrites::lockWorkspace($workspaceId);
            $current = DB::table('project_provisionings')->where('id', $row->id)->lockForUpdate()->first();
            if (! $current || $current->invitation_status !== 'sending') {
                return;
            }
            $project = Project::where('workspace_id', $workspaceId)->find($project->id);
            if (! $sent) {
                $client->accessTokens()->whereKey($link['id'])->update(['revoked_at' => now()]);
            }
            $this->transition($current, $project, $sent ? 'sent' : 'failed');
        }, 3);
    }

    public function recordInvitation(Project $project, ProjectClient $client, string $status): void
    {
        DB::transaction(function () use ($project, $client, $status): void {
            IntegrationWrites::lockWorkspace($project->workspace_id);
            $rows = DB::table('project_provisionings')->where('workspace_id', $project->workspace_id)
                ->where('project_id', $project->id)->where('project_client_id', $client->id)->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ($row->invitation_status !== $status) {
                    $this->transition($row, $project, $status);
                }
            }
        }, 3);
    }

    private function transition(object $row, ?Project $project, string $status): void
    {
        DB::table('project_provisionings')->where('id', $row->id)->update(['invitation_status' => $status, 'updated_at' => now()]);
        if ($project) {
            app(WebhookEvents::class)->invitation($project, $row->uuid, $status);
        }
    }
}
