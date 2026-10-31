<?php

namespace App\Http\Resources;

use App\Services\AccountProfile;
use App\Services\AgentAssignments;
use App\Services\ProjectPermissions;
use App\Services\ProjectStructure;
use App\Services\TaskAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = TaskAccess::isAdmin($request);
        $prefix = $admin ? 'admin' : 'client';

        $comments = $this->comments;
        if ($admin) {
            $internal = $this->resource->comments()->withoutGlobalScope('public_channel')->where('team_only', true)->with(['attachments' => fn ($q) => $q->withoutGlobalScope('public_channel')])->get();
            $comments = $comments->merge($internal)->sortBy('id');
        }

        return ['canShareAgentResults' => $this->when($admin, fn () => ProjectPermissions::effective($request, $this->resource->project)['manage_structure']), 'agentAssignments' => $this->when($admin, fn () => AgentAssignments::taskState($this->resource)), 'editVersion' => $this->resource->editVersion($admin), 'id' => $this->uuid, 'title' => $this->title, 'location' => $this->location ?? $this->section ?? trans('interface.sections.4'), 'section' => $this->projectSection ? ProjectStructure::sectionData($this->projectSection) : null,
            'sectionId' => $this->projectSection?->uuid, 'position' => $this->position, 'displayNumber' => ProjectStructure::number($this->resource), 'display_number' => ProjectStructure::number($this->resource),
            'description' => $this->description, 'expectedResult' => $this->expected_result ?? '', 'priority' => $this->priority, 'status' => $this->status, 'clientApproved' => $this->client_approved,
            'clientEditable' => $this->resource->isClientEditable(),
            'clientApprovedAt' => $this->client_approved_at?->toISOString(),
            'estimateHours' => $this->when($admin, $this->estimate_hours), 'price' => $this->when($admin, $this->price), 'developerNotes' => $this->when($admin, $this->developer_notes ?? ''),
            'attachments' => $this->attachments->whereNull('comment_id')->map(fn ($file) => $file->data($prefix))->values(),
            'comments' => $comments->map(fn ($comment) => ['sequence' => $comment->id, 'teamOnly' => $comment->team_only, 'agentKind' => $comment->agent?->kind, 'agentReport' => $comment->agentReport?->data(),
                ...($admin && $comment->author_type === 'admin' && $comment->authorUser ? ['avatarUrl' => app(AccountProfile::class)->state($comment->authorUser)['avatarUrl']] : []), 'id' => $comment->uuid, 'author' => $comment->author_type === 'agent' ? 'agent' : ($comment->author_type === 'admin' ? 'developer' : 'client'), 'authorId' => $comment->agent?->uuid ?? $comment->projectClient?->uuid, 'authorName' => $comment->agent?->name ?? ($comment->author_type === 'admin' ? trans('notifications.developer') : ($comment->projectClient?->name ?? trans('notifications.client'))), 'attachments' => $comment->attachments->map(fn ($file) => $file->data($prefix))->values(), 'text' => $comment->text, 'createdAt' => $comment->created_at->toISOString()])->values(),
            'history' => $this->history->filter(fn ($event) => $admin || (! ($event->meta['teamOnly'] ?? false) && ! in_array($event->event_type, ['estimate', 'price', 'developer_notes'])))->map(fn ($event) => [
                'agentState' => $event->meta['state'] ?? null, 'fileName' => $event->meta['fileName'] ?? null, 'actorType' => $event->actor_type === 'agent' ? 'agent' : ($event->actor_type === 'admin' ? 'developer' : 'client'), 'actorName' => $event->meta['agentName'] ?? $event->projectClient?->name, 'oldValue' => $event->old_value, 'newValue' => $event->new_value, 'id' => (string) $event->id, 'type' => $event->event_type, 'text' => $event->meta['text'] ?? trans('interface.task_changed'), 'createdAt' => $event->created_at->toISOString()])->values(),
            'createdAt' => $this->created_at->toISOString(), 'updatedAt' => $this->updated_at->toISOString()];
    }
}
