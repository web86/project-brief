<?php

namespace App\Http\Resources;

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

        return ['id' => $this->uuid, 'title' => $this->title, 'location' => $this->location ?? $this->section ?? 'Общее', 'section' => $this->projectSection ? ProjectStructure::sectionData($this->projectSection) : null,
            'sectionId' => $this->projectSection?->uuid, 'position' => $this->position, 'displayNumber' => ProjectStructure::number($this->resource), 'display_number' => ProjectStructure::number($this->resource),
            'description' => $this->description, 'expectedResult' => $this->expected_result ?? '', 'priority' => $this->priority, 'status' => $this->status, 'clientApproved' => $this->client_approved,
            'clientEditable' => $this->resource->isClientEditable(),
            'clientApprovedAt' => $this->client_approved_at?->toISOString(),
            'estimateHours' => $this->when($admin, $this->estimate_hours), 'price' => $this->when($admin, $this->price), 'developerNotes' => $this->when($admin, $this->developer_notes ?? ''),
            'attachments' => $this->attachments->map(fn ($file) => ['id' => $file->uuid, 'name' => $file->original_name, 'size' => $file->size, 'type' => $file->mime_type,
                'preview' => str_starts_with($file->mime_type, 'image/') ? '/api/'.$prefix.'/attachments/'.$file->uuid.'/preview' : null,
                'uploadedByName' => $file->projectClient?->name, 'downloadUrl' => '/api/'.$prefix.'/attachments/'.$file->uuid.'/download'])->values(),
            'comments' => $this->comments->map(fn ($comment) => ['id' => $comment->uuid, 'author' => $comment->author_type === 'admin' ? 'developer' : 'client', 'authorId' => $comment->projectClient?->uuid, 'authorName' => $comment->author_type === 'admin' ? 'Разработчик' : ($comment->projectClient?->name ?? 'Клиент'), 'text' => $comment->text, 'createdAt' => $comment->created_at->toISOString()])->values(),
            'history' => $this->history->filter(fn ($event) => $admin || ! in_array($event->event_type, ['estimate', 'price', 'developer_notes']))->map(fn ($event) => [
                'fileName' => $event->meta['fileName'] ?? null, 'actorType' => $event->actor_type === 'admin' ? 'developer' : 'client', 'actorName' => $event->projectClient?->name, 'oldValue' => $event->old_value, 'newValue' => $event->new_value, 'id' => (string) $event->id, 'type' => $event->event_type, 'text' => $event->meta['text'] ?? 'Изменение задачи', 'createdAt' => $event->created_at->toISOString()])->values(),
            'createdAt' => $this->created_at->toISOString(), 'updatedAt' => $this->updated_at->toISOString()];
    }
}
