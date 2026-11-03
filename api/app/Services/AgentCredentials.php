<?php

namespace App\Services;

use App\Models\ExternalAgent;
use App\Services\Plans\EffectiveParticipation;
use App\Services\Plans\ShadowGuard;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentCredentials
{
    public const HEADER = 'X-ProjectBrief-Agent-ID';

    public static function isAgent(object $token): bool
    {
        return (bool) ($token->external_agent_id || ($token->agent_shared ?? false));
    }

    public static function identity(Request $request, object $token): ExternalAgent
    {
        abort_unless(self::isAgent($token), 403);
        $query = ExternalAgent::where('workspace_id', $token->workspace_id)->where('active', true);
        app(EffectiveParticipation::class)->excludeHeld($query->getQuery(), $token->workspace_id, 'external_agent', 'external_agents.id');
        $claimed = $request->header(self::HEADER);
        if ($token->agent_shared) {
            abort_unless(is_string($claimed) && Str::isUuid($claimed), 403, 'An explicit linked agent ID is required.');
            $agent = $query->where('uuid', $claimed)->firstOrFail();
            $link = DB::table('agent_credential_links')->where('workspace_id', $token->workspace_id)->where('integration_token_id', $token->id)->where('external_agent_id', $agent->id)->first();
            abort_unless($link, 403, 'Agent is not linked to this credential.');
            $request->attributes->set('agent_credential_generation', $link->uuid);
        } else {
            $agent = $query->whereKey($token->external_agent_id)->firstOrFail();
            abort_if($claimed !== null && $claimed !== $agent->uuid, 403);
        }
        $request->attributes->set('integration_agent', $agent);

        app(ShadowGuard::class)->observe($agent->workspace_id, 'agent.read', []);

        return $agent;
    }

    public static function tokens(ExternalAgent $agent): Builder
    {
        return DB::table('integration_tokens')->where('workspace_id', $agent->workspace_id)->where(function (Builder $query) use ($agent): void {
            $query->where('external_agent_id', $agent->id)->orWhere(fn (Builder $shared) => $shared->where('agent_shared', true)->whereIn('id', DB::table('agent_credential_links')->where('workspace_id', $agent->workspace_id)->where('external_agent_id', $agent->id)->select('integration_token_id')));
        });
    }

    public static function cancelUnavailableDeliveries(ExternalAgent $agent): void
    {
        if (! AgentPermissions::hasToken($agent)) {
            $endpoints = DB::table('webhook_endpoints')->where('workspace_id', $agent->workspace_id)->where('external_agent_id', $agent->id)->select('id');
            DB::table('webhook_deliveries')->whereIn('webhook_endpoint_id', $endpoints)->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'error_code' => 'agent_access_revoked', 'updated_at' => now()]);
        }
    }

    public static function bind(Request $request, ExternalAgent $agent, ?string $uuid): void
    {
        $current = DB::table('agent_credential_links')->where('workspace_id', $agent->workspace_id)->where('external_agent_id', $agent->id)->first();
        $token = $uuid ? DB::table('integration_tokens')->where('workspace_id', $agent->workspace_id)->where('uuid', $uuid)->where('agent_shared', true)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->firstOrFail() : null;
        if (($current ? (int) $current->integration_token_id : null) === ($token ? (int) $token->id : null)) {
            return;
        }
        app(ShadowGuard::class)->observe($agent->workspace_id, 'agent.key', []);
        DB::table('agent_credential_links')->where('external_agent_id', $agent->id)->delete();
        if ($token) {
            DB::table('agent_credential_links')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $agent->workspace_id, 'integration_token_id' => $token->id, 'external_agent_id' => $agent->id, 'created_at' => now()]);
        }
        self::cancelUnavailableDeliveries($agent);
        app(AccountSecurity::class)->audit($request->user(), 'agent_credential_changed:'.$agent->id, ['object_type' => 'agent_credential', 'object_id' => $token?->uuid, 'agent_id' => $agent->id, 'credential_id' => $token?->uuid]);
    }
}
