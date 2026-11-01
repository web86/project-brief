<?php

namespace App\Services;

use App\Models\ExternalAgent;

class AgentPermissions
{
    public const DEFAULTS = ['assignedTasks' => true, 'projectTasks' => false, 'taskComments' => false, 'clientDiscussions' => false, 'teamChat' => false, 'taskAttachments' => false, 'discussionAttachments' => false, 'reports' => true, 'comments' => true, 'attachments' => true];

    public const SCOPE_PERMISSIONS = [
        'agent:runs:read' => ['assignedTasks'], 'agent:runs:write' => ['reports'], 'agent:messages:read' => ['assignedTasks'], 'agent:messages:create' => ['comments'],
        'agent:profile' => [], 'agent:tasks:read' => ['assignedTasks'], 'agent:tasks:report' => ['reports'], 'agent:comments:create' => ['comments'], 'agent:attachments:create' => ['attachments'],
        'agent:projects:tasks:read' => ['projectTasks'], 'agent:comments:read' => ['taskComments'], 'agent:discussions:read' => ['clientDiscussions'], 'agent:team-chat:read' => ['teamChat'], 'agent:attachments:read' => ['taskAttachments', 'discussionAttachments'],
    ];

    public static function normalize(?array $permissions): array
    {
        return array_replace(self::DEFAULTS, array_intersect_key(array_map(fn ($value) => $value === true, $permissions ?? []), self::DEFAULTS));
    }

    public static function fromScopes(array $scopes): array
    {
        $permissions = array_fill_keys(array_keys(self::DEFAULTS), false);
        foreach ($scopes as $scope) {
            foreach (self::SCOPE_PERMISSIONS[$scope] ?? [] as $permission) {
                $permissions[$permission] = true;
            }
        }

        return $permissions;
    }

    public static function hasToken(ExternalAgent $agent): bool
    {
        return AgentCredentials::tokens($agent)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }
}
