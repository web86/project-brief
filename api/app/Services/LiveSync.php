<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiveSync
{
    private bool $ready = false;

    public function record(int $workspaceId, ?int $projectId, string $kind, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();
        if (! $this->ready && ! ($this->ready = $connection->getSchemaBuilder()->hasTable('project_sync_events'))) {
            return;
        }
        $connection->afterCommit(function () use ($workspaceId, $projectId, $kind, $connection): void {
            // Serial allocation per workspace prevents a later committed ID from skipping an earlier concurrent event.
            $connection->transaction(function () use ($workspaceId, $projectId, $kind, $connection): void {
                // Reserve SQLite's writer before the first read, as in IntegrationWrites.
                if ($connection->getDriverName() === 'sqlite') {
                    $connection->table('workspaces')->where('id', $workspaceId)->update(['id' => $workspaceId]);
                }
                if (! $connection->table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first(['id'])) {
                    return;
                }
                if ($projectId && ! $connection->table('projects')->where('id', $projectId)->exists()) {
                    $projectId = null;
                }
                $connection->table('project_sync_events')->insert(['workspace_id' => $workspaceId, 'project_id' => $projectId, 'kind' => $kind, 'created_at' => now()]);
            }, 3);
        });
    }

    private function scope(Request $request): array
    {
        if ($client = $request->attributes->get('project_client')) {
            $project = $request->attributes->get('client_project');

            return [$project->workspace_id, 'client:'.$client->id.':'.$project->id, $project->id, null];
        }
        $member = app(CurrentWorkspace::class)->resolve($request);

        return [$member->workspace_id, 'admin:'.$member->user_id.':'.$member->id, null, $member];
    }

    private function cursor(string $scope, int $id, ?array $notifications = null): string
    {
        return Crypt::encryptString(json_encode(['scope' => $scope, 'id' => $id, 'at' => now()->timestamp, 'notifications' => $notifications ?? app(LiveNotifications::class)->seed()], JSON_THROW_ON_ERROR));
    }

    public function seed(Request $request): string
    {
        [$workspaceId, $scope] = $this->scope($request);

        return $this->cursor($scope, (int) DB::table('project_sync_events')->where('workspace_id', $workspaceId)->max('id'));
    }

    public function changes(Request $request): array
    {
        $data = $request->validate(['cursor' => ['required', 'string', 'max:2048']]);
        [$workspaceId, $scope, $projectId, $member] = $this->scope($request);
        try {
            $cursor = json_decode(Crypt::decryptString($data['cursor']), true, flags: JSON_THROW_ON_ERROR);
            if (($cursor['scope'] ?? '') !== $scope || ! is_int($cursor['id'] ?? null) || ! is_int($cursor['at'] ?? null) || $cursor['id'] < 0) {
                throw new DecryptException;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cursor' => 'Invalid sync cursor.']);
        }
        $rows = DB::table('project_sync_events as events')->where('events.workspace_id', $workspaceId)->where('events.id', '>', $cursor['id'])
            ->when($member && $member->role !== 'owner', fn ($q) => $q->where('events.kind', '!=', 'requests'))
            ->when($projectId, fn ($q) => $q->where('events.project_id', $projectId))
            ->when($member, fn ($q) => $q->where(fn ($q) => $q->whereNull('events.project_id')->orWhereExists(ProjectAccess::forMember($member)->withTrashed()->whereColumn('projects.id', 'events.project_id')->selectRaw('1')->toBase())))
            ->leftJoin('projects', 'projects.id', '=', 'events.project_id')->orderBy('events.id')->limit(101)->get(['events.id', 'events.kind', 'events.project_id', 'projects.uuid']);
        $reset = $cursor['at'] < now()->subDays(6)->timestamp || $rows->count() > 100 || $rows->contains(fn ($row) => $row->project_id === null);
        $id = $rows->isNotEmpty() ? (int) $rows->last()->id : $cursor['id'];
        if ($reset) {
            $id = (int) DB::table('project_sync_events')->where('workspace_id', $workspaceId)->max('id');
        }

        $watermark = $cursor['notifications'] ?? null;
        $notifications = is_array($watermark) && isset($watermark['history'], $watermark['comments']) && is_int($watermark['history']) && is_int($watermark['comments']) && $cursor['at'] >= now()->subMinutes(10)->timestamp
            ? app(LiveNotifications::class)->changes($request, $watermark, $member) : ['watermark' => app(LiveNotifications::class)->seed(), 'items' => [], 'additional' => 0, 'overflow' => false];
        if ($notifications['overflow']) {
            $notifications['watermark'] = app(LiveNotifications::class)->seed();
        }

        return ['cursor' => $this->cursor($scope, $id, $notifications['watermark']), 'notifications' => $notifications['items'], 'additionalNotifications' => $notifications['additional'], 'reset' => $reset, 'kinds' => $reset ? [] : $rows->pluck('kind')->unique()->values(), 'projects' => $reset ? [] : $rows->pluck('uuid')->filter()->unique()->values(), 'changed' => $reset || $rows->isNotEmpty()];
    }
}
