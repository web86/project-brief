<?php

namespace App\Services;

use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CurrentWorkspace
{
    public const SESSION_KEY = 'workspace_id';

    public function memberships(Request $request): Builder
    {
        abort_unless($request->user(), 401);

        return $request->user()->workspaceMembers()->active()->getQuery();
    }

    public function resolve(Request $request, bool $lock = false): WorkspaceMember
    {
        $query = $this->memberships($request)->with('workspace');
        if ($lock) {
            $query->lockForUpdate();
        }
        if ($request->session()->has(self::SESSION_KEY)) {
            $selected = $request->session()->get(self::SESSION_KEY);
            abort_unless(is_int($selected) && $selected > 0, 403, 'Invalid workspace selection.');
            $member = $query->where('workspace_id', $selected)->first();
            abort_unless($member, 403, 'Selected workspace is no longer accessible.');
        } else {
            $member = $query->orderBy('workspace_id')->first();
            abort_unless($member, 403, 'Active workspace membership is required.');
            $request->session()->put(self::SESSION_KEY, $member->workspace_id);
        }

        return $member;
    }

    public function select(Request $request, string $uuid): WorkspaceMember
    {
        $member = $this->memberships($request)->with('workspace')->whereHas('workspace', fn ($query) => $query->where('uuid', $uuid))->firstOrFail();
        $request->session()->put(self::SESSION_KEY, $member->workspace_id);

        return $member;
    }

    /** @return array{id: string, name: string} */
    public function state(WorkspaceMember $member, bool $withBrand = false): array
    {
        return ['id' => $member->workspace->uuid, 'name' => $member->workspace->name, ...($withBrand ? ['branding' => app(WorkspaceBranding::class)->portal($member->workspace)] : [])];
    }
}
