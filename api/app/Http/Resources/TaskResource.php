<?php

namespace App\Http\Resources;

use App\Services\TaskAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = TaskAccess::isAdmin($request);
        $prefix = $admin ? 'admin' : 'client';

        return ['id' => $this->uuid, 'title' => $this->title, 'location' => $this->location ?? $this->section ?? 'Общее', 'section' => $this->section ?? $this->location ?? 'Общее',
            'description' => $this->description, 'expectedResult' => $this->expected_result ?? '', 'priority' => $this->priority, 'status' => $this->status, 'clientApproved' => $this->client_approved,
            'clientApprovedAt' => $this->client_approved_at?->toISOString(),
            'estimateHours' => $this->when($admin, $this->estimate_hours), 'price' => $this->when($admin, $this->price), 'developerNotes' => $this->when($admin, $this->developer_notes ?? ''),
            'attachments' => $this->attachments->map(fn ($file) => ['id' => $file->uuid, 'name' => $file->original_name, 'size' => $file->size, 'type' => $file->mime_type,
                'preview' => str_starts_with($file->mime_type, 'image/') ? '/api/'.$prefix.'/attachments/'.$file->uuid.'/preview' : null,
                'downloadUrl' => '/api/'.$prefix.'/attachments/'.$file->uuid.'/download'])->values(),
            'comments' => $this->comments->map(fn ($comment) => ['id' => $comment->uuid, 'author' => $comment->author_type === 'admin' ? 'developer' : 'client', 'text' => $comment->text, 'createdAt' => $comment->created_at->toISOString()])->values(),
            'history' => $this->history->filter(fn ($event) => $admin || ! in_array($event->event_type, ['estimate', 'price', 'developer_notes']))->map(fn ($event) => [
                'id' => (string) $event->id, 'type' => $event->event_type, 'text' => $event->meta['text'] ?? 'Изменение задачи', 'createdAt' => $event->created_at->toISOString()])->values(),
            'createdAt' => $this->created_at->toISOString(), 'updatedAt' => $this->updated_at->toISOString()];
    }
}
