<?php

namespace App\Services;

use App\Http\Middleware\EnsureClientProjectAccess;
use App\Models\Project;
use App\Models\ProjectAccessToken;
use App\Models\ProjectClient;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ClientSso
{
    public const COOKIE = 'projectbrief_sso_handoff';

    public function trustedToken(int $id, int $workspaceId): ?object
    {
        $token = DB::table('integration_tokens')->where('workspace_id', $workspaceId)->where('id', $id)->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();

        return $token && in_array('clients:sso', json_decode($token->scopes, true), true) ? $token : null;
    }

    public function issue(object $authenticated, array $input): array
    {
        return DB::transaction(function () use ($authenticated, $input): array {
            IntegrationWrites::lockWorkspace($authenticated->workspace_id);
            $token = $this->trustedToken($authenticated->id, $authenticated->workspace_id);
            abort_unless($token, 401);
            $source = DB::table('integration_sources')->where('workspace_id', $token->workspace_id)->where('id', $token->integration_source_id)->where('sso_enabled', true)->first();
            abort_unless($source && $source->sso_origin, 403);
            $project = Project::where('workspace_id', $token->workspace_id)->where('uuid', $input['projectId'])->where('status', 'active')->lockForUpdate()->firstOrFail();
            abort_unless($this->granted($source->id, $token->workspace_id, $project->id), 403);
            $email = DeveloperInvitations::normalizeEmail($input['email']);
            abort_if($this->staff($email), 403);
            $clients = $project->clients()->where('active', true)->whereRaw('LOWER(TRIM(email)) = ?', [$email])->lockForUpdate()->get();
            abort_unless($clients->count() === 1, 403);
            $externalHash = hash('sha256', $input['externalUserId']);
            $emailHash = hash('sha256', $email);
            $bindings = DB::table('client_sso_bindings')->where('workspace_id', $token->workspace_id)->where('integration_source_id', $source->id);
            $binding = (clone $bindings)->where('external_hash', $externalHash)->first();
            if ($binding) {
                abort_unless(hash_equals($binding->email_hash, $emailHash), 409);
            } else {
                abort_if((clone $bindings)->where('email_hash', $emailHash)->exists(), 409);
                $id = DB::table('client_sso_bindings')->insertGetId(['workspace_id' => $token->workspace_id, 'integration_source_id' => $source->id, 'external_hash' => $externalHash, 'email_hash' => $emailHash, 'created_at' => now()]);
                $binding = DB::table('client_sso_bindings')->find($id);
            }
            $expired = DB::table('client_sso_handoffs')->where('workspace_id', $token->workspace_id)->whereNull('consumed_at')->where('expires_at', '<', now()->subDay())->limit(100)->pluck('id');
            DB::table('client_sso_handoffs')->whereIn('id', $expired)->delete();
            $code = bin2hex(random_bytes(32));
            $expires = now()->addSeconds(60);
            DB::table('client_sso_handoffs')->insert(['code_hash' => hash('sha256', $code), 'workspace_id' => $token->workspace_id, 'integration_source_id' => $source->id,
                'integration_token_id' => $token->id, 'client_sso_binding_id' => $binding->id, 'project_id' => $project->id, 'project_client_id' => $clients->first()->id, 'expires_at' => $expires, 'created_at' => now()]);

            return ['code' => $code, 'postUrl' => rtrim(config('projectbrief.frontend_url'), '/').'/client-sso/enter', 'expiresAt' => $expires->toISOString()];
        }, 3);
    }

    private function staff(string $email): bool
    {
        return User::whereRaw('LOWER(TRIM(email)) = ?', [$email])->exists();
    }

    private function granted(int $sourceId, int $workspaceId, int $projectId): bool
    {
        return DB::table('integration_source_projects')->where('workspace_id', $workspaceId)->where('integration_source_id', $sourceId)->where('project_id', $projectId)->exists();
    }

    public function handoff(#[\SensitiveParameter] mixed $code): ?object
    {
        return is_string($code) && preg_match('/^[a-f0-9]{64}$/D', $code) ? DB::table('client_sso_handoffs')->where('code_hash', hash('sha256', $code))->first() : null;
    }

    public function acceptsOrigin(object $handoff, ?string $origin): bool
    {
        $source = DB::table('integration_sources')->where('workspace_id', $handoff->workspace_id)->where('id', $handoff->integration_source_id)->where('sso_enabled', true)->first();

        return $source && is_string($origin) && is_string($source->sso_origin) && hash_equals($source->sso_origin, $origin) && ! $handoff->consumed_at && ! $handoff->revoked_at && Carbon::parse($handoff->expires_at)->isFuture();
    }

    private function eligible(object $row): ?ProjectClient
    {
        $token = $this->trustedToken($row->integration_token_id, $row->workspace_id);
        $source = DB::table('integration_sources')->where('workspace_id', $row->workspace_id)->where('id', $row->integration_source_id)->where('sso_enabled', true)->exists();
        $binding = DB::table('client_sso_bindings')->where('workspace_id', $row->workspace_id)->where('integration_source_id', $row->integration_source_id)->find($row->client_sso_binding_id);
        $client = ProjectClient::with('project')->find($row->project_client_id);
        if (! $token || $token->integration_source_id !== $row->integration_source_id || ! $source || ! $binding || ! $client?->active || ! $client->email ||
            $client->project_id !== $row->project_id || $client->project?->workspace_id !== $row->workspace_id || $client->project->status !== 'active' ||
            ! $this->granted($row->integration_source_id, $row->workspace_id, $row->project_id)) {
            return null;
        }
        $email = DeveloperInvitations::normalizeEmail($client->email);

        return ! $this->staff($email) && hash_equals($binding->email_hash, hash('sha256', $email)) ? $client : null;
    }

    public function consume(Request $request, #[\SensitiveParameter] string $code): ?array
    {
        $known = $this->handoff($code);
        if (! $known) {
            return null;
        }

        return DB::transaction(function () use ($request, $known): ?array {
            IntegrationWrites::lockWorkspace($known->workspace_id);
            $row = DB::table('client_sso_handoffs')->where('id', $known->id)->lockForUpdate()->first();
            if (! $row || $row->consumed_at || $row->revoked_at || ! Carbon::parse($row->expires_at)->isFuture() || ! ($client = $this->eligible($row))) {
                return null;
            }
            if ($request->user() || $request->session()->has('security.pending') || $request->session()->has('security.user')) {
                return null;
            }
            $existing = app(EnsureClientProjectAccess::class)->resolve($request);
            if ($existing && ($existing->project->workspace_id !== $row->workspace_id || DeveloperInvitations::normalizeEmail((string) $existing->projectClient->email) !== DeveloperInvitations::normalizeEmail($client->email) ||
                ($existing->projectClient->workspace_client_id && $client->workspace_client_id && $existing->projectClient->workspace_client_id !== $client->workspace_client_id))) {
                return null;
            }
            if (! DB::table('client_sso_handoffs')->where('id', $row->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['consumed_at' => now()])) {
                return null;
            }
            $access = $client->project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32)), 'client_sso_handoff_id' => $row->id,
                'expires_at' => now()->addMinutes(max(1, (int) config('session.lifetime'))), 'last_used_at' => now()]);

            return ['access' => $access, 'url' => ProjectProvisioning::url($client->project)];
        }, 3);
    }

    public function sessionUsable(ProjectAccessToken $access): bool
    {
        $row = DB::table('client_sso_handoffs')->find($access->client_sso_handoff_id);

        return $row && $row->consumed_at && ! $row->revoked_at && $row->project_id === $access->project_id && $row->project_client_id === $access->project_client_id && $this->eligible($row) !== null;
    }
}
