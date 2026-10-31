<?php

namespace App\Services;

use App\Models\ExternalAgent;
use Illuminate\Support\Facades\DB;

class AgentPermissions
{
    public const DEFAULTS = ['assignedTasks' => true, 'projectTasks' => false, 'taskComments' => false, 'clientDiscussions' => false, 'teamChat' => false, 'taskAttachments' => false, 'discussionAttachments' => false, 'reports' => true, 'comments' => true, 'attachments' => true];

    public const SCOPE_PERMISSIONS = [
        'agent:profile' => [], 'agent:tasks:read' => ['assignedTasks'], 'agent:tasks:report' => ['reports'], 'agent:comments:create' => ['comments'], 'agent:attachments:create' => ['attachments'],
        'agent:projects:tasks:read' => ['projectTasks'], 'agent:comments:read' => ['taskComments'], 'agent:discussions:read' => ['clientDiscussions'], 'agent:team-chat:read' => ['teamChat'], 'agent:attachments:read' => ['taskAttachments', 'discussionAttachments'],
    ];

    public static function normalize(?array $permissions): array
    {
        return array_replace(self::DEFAULTS, array_intersect_key(array_map(fn ($value) => $value === true, $permissions ?? []), self::DEFAULTS));
    }

    public static function allows(ExternalAgent $agent, string $permission): bool
    {
        return self::normalize($agent->permissions)[$permission] ?? false;
    }

    public static function scopes(ExternalAgent $agent): array
    {
        return array_values(array_filter(array_keys(self::SCOPE_PERMISSIONS), fn ($scope) => ! self::SCOPE_PERMISSIONS[$scope] || collect(self::SCOPE_PERMISSIONS[$scope])->contains(fn ($permission) => self::allows($agent, $permission))));
    }

    public static function hasToken(ExternalAgent $agent): bool
    {
        return DB::table('integration_tokens')->where('external_agent_id', $agent->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }
}
