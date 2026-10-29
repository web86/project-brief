<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Workspace;
use App\Services\AccountSecurity;
use App\Services\ClientSso;
use App\Services\CurrentWorkspace;
use App\Services\IntegrationTokens;
use App\Services\IntegrationWrites;
use App\Services\WebhookDestination;
use App\Services\WebhookEvents;
use App\Services\WebhookProjectFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    private function workspace(Request $request): int
    {
        $member = app(CurrentWorkspace::class)->resolve($request, DB::transactionLevel() > 0);
        abort_unless($member->role === 'owner', 403);
        if (! $request->isMethodSafe()) {
            app(AccountSecurity::class)->requireFresh($request);
        }

        return $member->workspace_id;
    }

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);

        return response()->json(['projects' => Project::where('workspace_id', $workspace)->orderBy('title')->orderBy('id')->get(['uuid', 'title'])->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->title]), 'sources' => DB::table('integration_sources')->where('workspace_id', $workspace)->orderBy('name')->get()->map(fn ($source) => ['id' => $source->uuid, 'name' => $source->name, 'ssoEnabled' => (bool) $source->sso_enabled, 'ssoOrigin' => $source->sso_origin, 'automaticSsoProjectIds' => Project::where('workspace_id', $workspace)->whereIn('id', app(ClientSso::class)->automaticProjects($source->id, $workspace))->orderBy('uuid')->pluck('uuid')->all(), 'ssoProjectIds' => Project::where('workspace_id', $workspace)->whereIn('id', DB::table('integration_source_projects')->where('workspace_id', $workspace)->where('integration_source_id', $source->id)->select('project_id'))->orderBy('uuid')->pluck('uuid')->all()]), 'scopes' => IntegrationTokens::SCOPES, 'events' => WebhookEvents::TYPES,
            'tokens' => DB::table('integration_tokens')->where('workspace_id', $workspace)->orderByDesc('id')->get(['uuid', 'name', 'scopes', 'expires_at', 'last_used_at', 'revoked_at', 'created_at', 'provisioning_enabled', 'integration_source_id'])->map(fn ($t) => ['sourceId' => $t->integration_source_id ? DB::table('integration_sources')->where('workspace_id', $workspace)->where('id', $t->integration_source_id)->value('uuid') : null, 'id' => $t->uuid, 'name' => $t->name, 'scopes' => json_decode($t->scopes, true), 'expiresAt' => $t->expires_at, 'lastUsedAt' => $t->last_used_at, 'revokedAt' => $t->revoked_at, 'createdAt' => $t->created_at, 'provisioningEnabled' => (bool) $t->provisioning_enabled]),
            'webhooks' => DB::table('webhook_endpoints')->where('workspace_id', $workspace)->orderByDesc('id')->get(['id', 'workspace_id', 'uuid', 'name', 'url', 'events', 'enabled', 'created_at', 'project_filter'])->map(fn ($e) => ['id' => $e->uuid, 'name' => $e->name, 'url' => $e->url, 'events' => json_decode($e->events, true), 'projectFilter' => $e->project_filter, 'projectIds' => app(WebhookProjectFilter::class)->ids($e), 'enabled' => (bool) $e->enabled, 'createdAt' => $e->created_at]),
        ])->header('Cache-Control', 'no-store');
    }

    public function token(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'regex:/\S/u'], 'scopes' => ['required', 'array', 'min:1', 'max:'.count(IntegrationTokens::SCOPES)], 'scopes.*' => ['required', Rule::in(IntegrationTokens::SCOPES), 'distinct'], 'sourceId' => ['nullable', 'uuid', 'prohibits:sourceName'], 'sourceName' => ['nullable', 'string', 'max:100', 'regex:/\S/u', 'prohibits:sourceId'], 'expiresAt' => ['nullable', 'date', 'after:now', 'before:'.now()->addYears(2)->toDateString()]]);
        if (! empty($data['expiresAt'])) {
            $data['expiresAt'] = Carbon::parse($data['expiresAt'])->utc();
        }

        return DB::transaction(function () use ($request, $workspace, $data): JsonResponse {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $this->workspace($request);
            abort_if(DB::table('integration_tokens')->where('workspace_id', $workspace)->count() >= 100, 422, 'Token limit reached.');

            if (! empty($data['sourceId'])) {
                $data['sourceId'] = DB::table('integration_sources')->where('workspace_id', $workspace)->where('uuid', $data['sourceId'])->value('id');
                abort_unless($data['sourceId'], 422, 'Unknown integration source.');
            } elseif (! empty($data['sourceName'])) {
                abort_if(DB::table('integration_sources')->where('workspace_id', $workspace)->count() >= 100, 422, 'Source limit reached.');
                $data['sourceId'] = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace, 'name' => trim($data['sourceName']), 'created_at' => now(), 'updated_at' => now()]);
            }
            abort_if((in_array('requests:create', $data['scopes'], true) || in_array('clients:sso', $data['scopes'], true)) && empty($data['sourceId']), 422, 'Select or create a persistent integration source.');

            return response()->json(app(IntegrationTokens::class)->create($workspace, $data), 201)->header('Cache-Control', 'no-store');
        });
    }

    public function sourceSso(Request $request, string $uuid): JsonResponse
    {
        $workspaceId = $this->workspace($request);
        abort_if(array_diff(array_keys($request->all()), ['ssoEnabled', 'ssoOrigin', 'projectIds']), 422);
        $data = $request->validate(['ssoEnabled' => ['required', 'boolean'], 'ssoOrigin' => ['nullable', 'string', 'url:https', 'max:255'], 'projectIds' => ['present', 'array', 'max:1000'], 'projectIds.*' => ['required', 'uuid', 'distinct']]);
        $origin = $data['ssoOrigin'] ?? null;
        if ($origin !== null) {
            $parts = parse_url($origin);
            abort_if(isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true), 422);
            $origin = 'https://'.strtolower($parts['host']).(isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '');
        }
        abort_if($data['ssoEnabled'] && ! $origin, 422);
        DB::transaction(function () use ($request, $workspaceId, $uuid, $data, $origin): void {
            IntegrationWrites::lockWorkspace($workspaceId);
            $this->workspace($request);
            $source = DB::table('integration_sources')->where('workspace_id', $workspaceId)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $ids = Project::where('workspace_id', $workspaceId)->whereIn('uuid', $data['projectIds'])->orderBy('id')->pluck('id')->all();
            abort_unless(count($ids) === count($data['projectIds']), 422);
            $current = DB::table('integration_source_projects')->where('workspace_id', $workspaceId)->where('integration_source_id', $source->id)->orderBy('project_id')->pluck('project_id')->all();
            if ((bool) $source->sso_enabled !== (bool) $data['ssoEnabled'] || $source->sso_origin !== $origin || $current !== $ids) {
                DB::table('client_sso_handoffs')->where('workspace_id', $workspaceId)->where('integration_source_id', $source->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                DB::table('integration_source_projects')->where('workspace_id', $workspaceId)->where('integration_source_id', $source->id)->delete();
                foreach ($ids as $id) {
                    DB::table('integration_source_projects')->insert(['workspace_id' => $workspaceId, 'integration_source_id' => $source->id, 'project_id' => $id]);
                }
                DB::table('integration_sources')->where('id', $source->id)->update(['sso_enabled' => $data['ssoEnabled'], 'sso_origin' => $origin, 'updated_at' => now()]);
            }
        }, 3);

        return response()->json(['ok' => true]);
    }

    public function updateToken(Request $request, string $uuid): JsonResponse
    {
        $workspace = $this->workspace($request);
        $data = $request->validate(['provisioningEnabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $workspace, $uuid, $data): void {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $this->workspace($request);
            $token = DB::table('integration_tokens')->where('workspace_id', $workspace)->where('uuid', $uuid)->whereNull('revoked_at')->lockForUpdate()->first();
            abort_unless($token, 404);
            abort_unless(in_array('projects:provision', json_decode($token->scopes, true), true), 422);
            DB::table('integration_tokens')->where('id', $token->id)->update(['provisioning_enabled' => $data['provisioningEnabled'], 'updated_at' => now()]);
        }, 3);

        return response()->json(['ok' => true]);
    }

    public function operations(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = DB::table('provisioning_operations as o')
            ->join('integration_tokens as t', 't.id', '=', 'o.integration_token_id')
            ->leftJoin('project_provisionings as p', fn ($join) => $join->on('p.uuid', '=', 'o.provisioning_uuid')->on('p.workspace_id', '=', 'o.workspace_id'))
            ->where('o.workspace_id', $workspace)->orderByDesc('o.id')
            ->paginate(25, ['o.operation_type', 'o.project_request_uuid', 'o.id', 'o.outcome', 'o.http_status', 'o.created_at', 't.name', 'p.invitation_status', 'p.uuid']);

        return response()->json(['data' => $page->getCollection()->map(fn ($row) => [
            'operationType' => $row->operation_type, 'requestId' => $row->project_request_uuid, 'id' => $row->id, 'operationId' => $row->uuid, 'tokenName' => $row->name, 'outcome' => $row->outcome,
            'httpStatus' => $row->http_status, 'invitation' => $row->invitation_status, 'createdAt' => $row->created_at,
        ]), 'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]])->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, string $uuid): JsonResponse
    {
        $workspace = $this->workspace($request);
        DB::transaction(function () use ($request, $workspace, $uuid): void {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $this->workspace($request);
            $row = DB::table('integration_tokens')->where('workspace_id', $workspace)->where('uuid', $uuid)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('integration_tokens')->where('id', $row->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    public function webhook(Request $request, ?string $uuid = null): JsonResponse
    {
        $workspace = $this->workspace($request);
        $required = $uuid ? 'sometimes' : 'required';
        $data = $request->validate(['name' => [$required, 'string', 'max:100', 'regex:/\S/u'], 'url' => [$required, 'string', 'max:2048'], 'events' => [$required, 'array', 'min:1', 'max:'.count(WebhookEvents::TYPES)], 'events.*' => [Rule::in(WebhookEvents::TYPES), 'distinct'], 'enabled' => ['sometimes', 'boolean'], 'projectFilter' => ['sometimes', Rule::in(['all', 'selected'])], 'projectIds' => ['sometimes', 'array', 'max:1000'], 'projectIds.*' => ['required', 'uuid', 'distinct']]);
        if (isset($data['url'])) {
            app(WebhookDestination::class)->resolve($data['url']);
        }

        return DB::transaction(function () use ($request, $workspace, $uuid, $data): JsonResponse {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $this->workspace($request);
            $existing = $uuid ? DB::table('webhook_endpoints')->where('workspace_id', $workspace)->where('uuid', $uuid)->first() : null;
            abort_if($uuid && ! $existing, 404);
            $filters = app(WebhookProjectFilter::class);
            $filter = $filters->input($workspace, $data, $existing);
            unset($data['projectFilter'], $data['projectIds']);
            if ($uuid) {
                $endpoint = $this->endpoint($workspace, $uuid);
                $values = [...$data, 'updated_at' => now()];
                if (isset($data['events'])) {
                    $values['events'] = json_encode($data['events'], JSON_THROW_ON_ERROR);
                }
                $filterChanged = $filter !== null && $filters->replace($endpoint, $filter);
                if ($filter !== null) {
                    $values['project_filter'] = $filter['mode'];
                }
                $changed = $filterChanged || (isset($data['url']) && $data['url'] !== $endpoint->url) || (isset($data['events']) && $data['events'] !== json_decode($endpoint->events, true)) || (isset($data['enabled']) && ! $data['enabled']);
                if ($changed) {
                    $values['secret_version'] = $endpoint->secret_version + 1;
                    $this->cancel($endpoint->id);
                }
                DB::table('webhook_endpoints')->where('id', $endpoint->id)->update($values);

                return response()->json(['ok' => true]);
            }
            abort_if(DB::table('webhook_endpoints')->where('workspace_id', $workspace)->count() >= 20, 422, 'Endpoint limit reached.');
            $secret = 'whsec_'.bin2hex(random_bytes(32));
            $id = (string) Str::uuid();
            DB::table('webhook_endpoints')->insert(['project_filter' => $filter['mode'], 'uuid' => $id, 'workspace_id' => $workspace, 'name' => $data['name'], 'url' => $data['url'], 'events' => json_encode($data['events'], JSON_THROW_ON_ERROR), 'enabled' => $data['enabled'] ?? true, 'secret' => Crypt::encryptString($secret), 'secret_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

            $created = DB::table('webhook_endpoints')->where('uuid', $id)->first();
            $filters->replace($created, $filter);

            return response()->json(['id' => $id, 'secret' => $secret], 201)->header('Cache-Control', 'no-store');
        });
    }

    private function endpoint(int $workspace, string $uuid): object
    {
        $endpoint = DB::table('webhook_endpoints')->where('workspace_id', $workspace)->where('uuid', $uuid)->lockForUpdate()->first();
        abort_unless($endpoint, 404);

        return $endpoint;
    }

    private function cancel(int $id): void
    {
        DB::table('webhook_deliveries')->where('webhook_endpoint_id', $id)->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'error_code' => 'endpoint_changed', 'updated_at' => now()]);
    }

    public function rotate(Request $request, string $uuid): JsonResponse
    {
        $workspace = $this->workspace($request);

        return DB::transaction(function () use ($request, $workspace, $uuid): JsonResponse {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $this->workspace($request);
            $endpoint = $this->endpoint($workspace, $uuid);
            $secret = 'whsec_'.bin2hex(random_bytes(32));
            $this->cancel($endpoint->id);
            DB::table('webhook_endpoints')->where('id', $endpoint->id)->update(['secret' => Crypt::encryptString($secret), 'secret_version' => $endpoint->secret_version + 1, 'updated_at' => now()]);

            return response()->json(['secret' => $secret])->header('Cache-Control', 'no-store');
        });
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $workspace = $this->workspace($request);

        return DB::transaction(function () use ($request, $workspace, $uuid): JsonResponse {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $this->workspace($request);
            $endpoint = $this->endpoint($workspace, $uuid);
            DB::table('webhook_endpoints')->where('id', $endpoint->id)->delete();

            return response()->json(['ok' => true]);
        });
    }

    public function test(Request $request, string $uuid): JsonResponse
    {
        $workspace = $this->workspace($request);

        return DB::transaction(function () use ($request, $workspace, $uuid): JsonResponse {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $this->workspace($request);
            $endpoint = $this->endpoint($workspace, $uuid);
            abort_unless($endpoint->enabled, 422, 'Enable this endpoint first.');
            $workspaceUuid = Workspace::findOrFail($workspace)->uuid;
            $id = app(WebhookEvents::class)->enqueue($workspace, 'webhook.test', ['workspaceId' => $workspaceUuid, 'projectId' => null, 'taskId' => null, 'actor' => ['type' => 'admin', 'id' => null], 'changes' => (object) [], 'origin' => 'test'], $endpoint->id);

            return response()->json(['eventId' => $id, 'queued' => true], 202);
        });
    }

    public function history(Request $request, string $uuid): JsonResponse
    {
        $endpoint = $this->endpoint($this->workspace($request), $uuid);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = DB::table('webhook_deliveries')->where('webhook_endpoint_id', $endpoint->id)->orderByDesc('id')->paginate(25);

        return response()->json(['data' => $page->getCollection()->map(function ($d) use ($endpoint): array {
            $event = DB::table('webhook_events')->find($d->webhook_event_id);
            $payload = json_decode($event->body, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => $d->uuid, 'eventId' => $event->uuid, 'type' => $event->type, 'status' => $d->status, 'attempts' => $d->attempts, 'httpStatus' => $d->http_status, 'errorCode' => $d->error_code, 'availableAt' => $d->available_at, 'createdAt' => $d->created_at, 'canRetry' => app(WebhookProjectFilter::class)->matches($endpoint, $event->type, $payload['projectId'] ?? null) && $d->status === 'failed' && $endpoint->enabled && $d->secret_version === $endpoint->secret_version, 'history' => DB::table('webhook_attempts')->where('webhook_delivery_id', $d->id)->orderBy('id')->get(['attempt', 'status', 'http_status', 'error_code', 'duration_ms', 'created_at'])];
        }), 'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]])->header('Cache-Control', 'no-store');
    }

    public function retry(Request $request, string $uuid, string $delivery): JsonResponse
    {
        $workspace = $this->workspace($request);

        return DB::transaction(function () use ($request, $workspace, $uuid, $delivery): JsonResponse {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $this->workspace($request);
            $endpoint = $this->endpoint($workspace, $uuid);
            $row = DB::table('webhook_deliveries')->where('webhook_endpoint_id', $endpoint->id)->where('uuid', $delivery)->first();
            abort_unless($row, 404);
            $event = $row ? DB::table('webhook_events')->find($row->webhook_event_id) : null;
            $payload = $event ? json_decode($event->body, true, 512, JSON_THROW_ON_ERROR) : [];
            abort_unless($event && $event->workspace_id === $endpoint->workspace_id && app(WebhookProjectFilter::class)->matches($endpoint, $event->type, $payload['projectId'] ?? null), 409);
            abort_unless($endpoint->enabled && $endpoint->secret_version === $row->secret_version && $row->status === 'failed', 409);
            DB::table('webhook_deliveries')->where('id', $row->id)->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'http_status' => null, 'error_code' => null, 'updated_at' => now()]);

            return response()->json(['queued' => true], 202);
        });
    }
}
