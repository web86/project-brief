<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/admin/*');

        return ['id' => $this->uuid, 'name' => $this->title, 'website' => $this->website_url ?? '', 'description' => '', 'currency' => $this->currency,
            'status' => $this->status, 'sections' => $this->sections ?? ['Главная', 'Каталог', 'Карточка товара', 'Контакты', 'Общее'],
            'tasksCount' => $this->when($admin, $this->tasks_count), 'doneCount' => $this->when($admin, $this->done_count),
            'clientName' => $this->when($admin, $this->client_name), 'clientEmail' => $this->when($admin, $this->client_email),
            'accessLinks' => $this->when($admin && $this->relationLoaded('accessTokens'), fn () => $this->accessTokens->map(fn ($token) => [
                'id' => $token->id, 'expiresAt' => $token->expires_at?->toISOString(), 'revokedAt' => $token->revoked_at?->toISOString(),
                'lastUsedAt' => $token->last_used_at?->toISOString(), 'active' => $token->isUsable(), 'createdAt' => $token->created_at->toISOString(),
            ])->values())];
    }
}
