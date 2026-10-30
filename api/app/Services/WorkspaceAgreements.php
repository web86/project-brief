<?php

namespace App\Services;

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

class WorkspaceAgreements
{
    public const LOCALES = ['ru', 'en', 'tr', 'zh-CN'];

    public function settings(Workspace $workspace): array
    {
        return array_replace(['enabled' => false, 'draft' => ['date' => '', 'texts' => array_fill_keys(self::LOCALES, [])], 'published' => null], $workspace->administrator_agreement ?? []);
    }

    public function required(WorkspaceMember $member): bool
    {
        if ($member->role !== 'admin' || ! $member->agreement_requirement || ! $this->settings($member->workspace)['enabled']) {
            return false;
        }

        return ! DB::table('workspace_agreement_acceptances')->where('workspace_id', $member->workspace_id)->where('workspace_member_id', $member->id)->where('user_id', $member->user_id)->where('requirement_id', $member->agreement_requirement)->exists();
    }

    public function status(WorkspaceMember $member, ?object $acceptance = null): array
    {
        $required = $member->role === 'admin' && $member->agreement_requirement && $this->settings($member->workspace)['enabled'] && $acceptance?->requirement_id !== $member->agreement_requirement;

        return ['status' => $required ? ($acceptance ? 'renewal' : 'pending') : ($acceptance ? 'accepted' : 'notRequired'),
            'version' => $acceptance?->version, 'locale' => $acceptance?->locale, 'acceptedAt' => $acceptance?->accepted_at];
    }

    public function invitationStatus(Workspace $workspace): array
    {
        return ['status' => $this->settings($workspace)['enabled'] ? 'pending' : 'notRequired'];
    }

    public function enroll(WorkspaceMember $member): void
    {
        $settings = $this->settings($member->workspace);
        if ($settings['enabled'] && $settings['published']) {
            $member->forceFill(['agreement_requirement' => $settings['published']['id']])->save();
        }
    }

    public function publicUrl(Workspace $workspace): ?string
    {
        return $this->settings($workspace)['published'] ? '/w/'.$workspace->uuid.'/agreement' : null;
    }
}
