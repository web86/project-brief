<?php

namespace App\Services;

use App\Models\ProjectRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class ProjectRequests
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    public function input(Request $request): array
    {
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 32768, 413);
        abort_if(count(array_diff(array_keys($request->all()), ['externalId', 'client', 'project'])) > 0, 422);
        $data = $request->validate([
            'externalId' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'client' => ['required', 'array:name,email,company,website'],
            'client.name' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'client.email' => ['required', 'string', 'email', 'max:255'],
            'client.company' => ['nullable', 'string', 'max:255'],
            'client.website' => ['nullable', 'url:http,https', 'max:2048'],
            'project' => ['required', 'array:name,description,language'],
            'project.name' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'project.description' => ['nullable', 'string', 'max:10000'],
            'project.language' => ['required', Rule::in(SupportedLocales::codes())],
        ]);

        return ['externalId' => trim($data['externalId']),
            'client' => ['name' => trim($data['client']['name']), 'email' => DeveloperInvitations::normalizeEmail($data['client']['email']), 'company' => $data['client']['company'] ?? null, 'website' => $data['client']['website'] ?? null],
            'project' => ['name' => trim($data['project']['name']), 'description' => $data['project']['description'] ?? null, 'language' => $data['project']['language']]];
    }

    /** IntegrationWrites owns the workspace lock and commits notifications/outbox with the request. */
    public function create(object $token, array $input): array
    {
        $source = DB::table('integration_sources')->where('workspace_id', $token->workspace_id)->where('id', $token->integration_source_id)->first();
        abort_unless($source, 422, 'A persistent integration source is required.');
        $hash = IntegrationWrites::fingerprint($input);
        $existing = ProjectRequest::where('workspace_id', $token->workspace_id)->where('integration_source_id', $source->id)->where('external_hash', hash('sha256', $input['externalId']))->first();
        if ($existing) {
            abort_unless(hash_equals($existing->body_hash, $hash), 409, 'External ID already belongs to a different request.');

            return [['data' => $this->creationResource($existing)], 200];
        }
        $row = ProjectRequest::create([
            'workspace_id' => $token->workspace_id, 'integration_source_id' => $source->id, 'integration_token_id' => $token->id,
            'external_id' => $input['externalId'], 'external_hash' => hash('sha256', $input['externalId']), 'body_hash' => $hash,
            'client_name' => $input['client']['name'], 'email' => $input['client']['email'], 'company' => $input['client']['company'], 'website' => $input['client']['website'],
            'project_name' => $input['project']['name'], 'description' => $input['project']['description'], 'language' => $input['project']['language'], 'status' => 'pending',
        ]);
        app(WebhookEvents::class)->projectRequest($row, 'created');
        app(LiveSync::class)->record($row->workspace_id, null, 'requests');
        DB::afterCommit(fn () => app(ProjectNotificationService::class)->notifyRequest($row));

        return [['data' => $this->creationResource($row)], 201];
    }

    public function find(int $workspaceId, string $uuid): ProjectRequest
    {
        return ProjectRequest::where('workspace_id', $workspaceId)->where('uuid', $uuid)->firstOrFail();
    }

    public function listing(Request $request, int $workspaceId): array
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(self::STATUSES)], 'search' => ['sometimes', 'nullable', 'string', 'max:255'], 'sort' => ['sometimes', Rule::in(['asc', 'desc'])]]);
        $query = ProjectRequest::where('workspace_id', $workspaceId)->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        if (trim($data['search'] ?? '') !== '') {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($data['search']))).'%';
            $query->where(function (Builder $query) use ($search): void {
                foreach (['client_name', 'company', 'email', 'project_name'] as $column) {
                    $query->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '!'", [$search]);
                }
            });
        }
        $page = $query->orderBy('created_at', $data['sort'] ?? 'desc')->orderBy('id', $data['sort'] ?? 'desc')->paginate($data['perPage'] ?? 25);

        return ['data' => $page->getCollection()->map(fn ($row) => $this->resource($row)), 'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(), 'pending' => $this->count($workspaceId)]];
    }

    public function count(int $workspaceId): int
    {
        return ProjectRequest::where('workspace_id', $workspaceId)->where('status', 'pending')->count();
    }

    public function decisionInput(Request $request, string $action): array
    {
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 4096, 413);
        $key = $action === 'approve' ? 'sendInvitation' : 'reason';
        abort_if(count(array_diff(array_keys($request->all()), [$key])) > 0, 422);
        $data = $request->validate($action === 'approve' ? ['sendInvitation' => ['sometimes', 'boolean']] : ['reason' => ['nullable', 'string', 'max:2000']]);

        return $action === 'approve' ? ['sendInvitation' => (bool) ($data['sendInvitation'] ?? true)] : ['reason' => trim($data['reason'] ?? '') ?: null];
    }

    /** Caller holds the workspace lock for UI and API decisions, before looking up the request. */
    public function decide(int $workspaceId, string $uuid, string $action, array $input, array $actor): array
    {
        $row = ProjectRequest::where('workspace_id', $workspaceId)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
        $status = $action === 'approve' ? 'approved' : 'rejected';
        if ($row->status !== 'pending') {
            request()->attributes->set('project_request_reused', true);
            abort_unless($row->status === $status && ($status === 'approved' ? $row->send_invitation === $input['sendInvitation'] : $row->rejection_reason === $input['reason']), 409, 'The request already has a final decision.');

            return [['data' => $this->creationResource($row)], 200];
        }
        $result = $status === 'approved' ? app(ProjectProvisioning::class)->createForRequest($row, $input['sendInvitation']) : null;
        $row->forceFill(['status' => $status, 'reviewed_at' => now(), 'decided_by_type' => $actor['type'], 'decided_by_uuid' => $actor['id'], 'decided_by_name' => $actor['name'],
            'rejection_reason' => $input['reason'] ?? null, 'send_invitation' => $input['sendInvitation'] ?? null, 'result' => $result,
            'project_provisioning_id' => $result ? DB::table('project_provisionings')->where('workspace_id', $workspaceId)->where('uuid', $result['operationId'])->value('id') : null])->save();
        app(WebhookEvents::class)->projectRequest($row, $action === 'approve' ? 'approved' : 'rejected');
        app(LiveSync::class)->record($workspaceId, null, 'requests');

        return [['data' => $this->creationResource($row)], 200];
    }

    public function deliver(ProjectRequest $row): void
    {
        if ($row->status !== 'approved') {
            return;
        }
        try {
            app(ProjectProvisioning::class)->deliver($row->result['operationId'], $row->workspace_id);
        } catch (Throwable $exception) {
            app(SafeExceptionReporter::class)->report($exception);
        }
    }

    public function creationResource(ProjectRequest $row): array
    {
        return ['id' => $row->uuid, 'requestId' => $row->uuid, 'status' => $row->status, 'createdAt' => $row->created_at->toISOString()];
    }

    public function resource(ProjectRequest $row): array
    {
        $source = DB::table('integration_sources')->where('workspace_id', $row->workspace_id)->find($row->integration_source_id);
        $result = $row->result;
        if ($result) {
            $result['invitation'] = app(ProjectProvisioning::class)->status($result['operationId'], $row->workspace_id);
            unset($result['created']);
        }

        return ['id' => $row->uuid, 'requestId' => $row->uuid, 'externalId' => $row->external_id, 'source' => ['id' => $source->uuid, 'name' => $source->name],
            'client' => ['name' => $row->client_name, 'email' => $row->email, 'company' => $row->company, 'website' => $row->website],
            'project' => ['name' => $row->project_name, 'description' => $row->description, 'language' => $row->language],
            'status' => $row->status, 'createdAt' => $row->created_at->toISOString(), 'updatedAt' => $row->updated_at->toISOString(), 'reviewedAt' => $row->reviewed_at?->toISOString(),
            'decision' => $row->reviewed_at ? ['actor' => ['type' => $row->decided_by_type, 'id' => $row->decided_by_uuid, 'name' => $row->decided_by_name], 'reason' => $row->rejection_reason, 'sendInvitation' => $row->send_invitation] : null,
            'result' => $result, 'projectId' => $result['projectId'] ?? null, 'clientId' => $result['clientId'] ?? null, 'projectUrl' => $result['projectUrl'] ?? null, 'invitation' => $result['invitation'] ?? null];
    }
}
