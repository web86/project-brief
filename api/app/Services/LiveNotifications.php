<?php

namespace App\Services;

use App\Models\ProjectClient;
use App\Models\ProjectNotificationSetting;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LiveNotifications
{
    public function seed(): array
    {
        // IDs are only opaque cursor watermarks; authorization is applied on every read.
        $row = DB::query()->selectSub(DB::table('task_history')->selectRaw('COALESCE(MAX(id), 0)'), 'history')
            ->selectSub(DB::table('comments')->selectRaw('COALESCE(MAX(id), 0)'), 'comments')->first();

        return ['history' => (int) $row->history, 'comments' => (int) $row->comments, 'startHistory' => (int) $row->history, 'startComments' => (int) $row->comments, 'since' => now()->timestamp];
    }

    private function scoped(Request $request, Builder $query, ?WorkspaceMember $member): Builder
    {
        return $query->whereIn('p.id', TaskAccess::isAdmin($request) ? ProjectAccess::forMember($member)->select('projects.id')->toBase()
            : DB::table('projects')->select('id')->where('id', $request->attributes->get('client_project')->id))
            ->whereNull('p.deleted_at')->where('p.status', 'active');
    }

    public function changes(Request $request, array $watermark, ?WorkspaceMember $member): array
    {
        $recipient = TaskAccess::recipient($request);
        $latest = $this->seed();
        $overlap = now()->subSeconds(120);

        $history = $this->scoped($request, DB::table('task_history as h')->join('tasks as t', 't.id', '=', 'h.task_id')->join('projects as p', 'p.id', '=', 't.project_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.actor_user_id')->leftJoin('project_clients as pc', 'pc.id', '=', 'h.project_client_id')->whereNull('t.deleted_at'), $member)
            ->where('h.id', '>', $watermark['startHistory'] ?? $watermark['history'])
            ->where(fn ($query) => $query->where('h.id', '>', $watermark['history'])->orWhere('h.created_at', '>=', $overlap))->orderBy('h.id')->limit(51)
            ->get(['h.id', 'h.event_type', 'h.actor_type', 'h.actor_user_id', 'h.project_client_id', 'h.old_value', 'h.new_value', 'p.id as project_id', 'p.uuid as project_uuid', 'p.title as project', 't.uuid as task_uuid', 't.title as task', DB::raw('COALESCE(u.name, pc.name) as author'), DB::raw('COALESCE(u.email, pc.email) as actor_email')]);
        $comments = $this->scoped($request, DB::table('comments as c')->leftJoin('tasks as t', 't.id', '=', 'c.task_id')
            ->join('projects as p', 'p.id', '=', DB::raw('COALESCE(t.project_id, c.project_id)'))
            ->leftJoin('users as u', 'u.id', '=', 'c.author_user_id')->leftJoin('project_clients as pc', 'pc.id', '=', 'c.project_client_id')
            ->where(fn ($q) => $q->whereNull('c.task_id')->orWhereNull('t.deleted_at')), $member)
            ->when(! TaskAccess::isAdmin($request), fn ($q) => $q->where('c.team_only', false))
            ->where('c.id', '>', $watermark['startComments'] ?? $watermark['comments'])
            ->where(fn ($query) => $query->where('c.id', '>', $watermark['comments'])->orWhere('c.created_at', '>=', $overlap))->orderBy('c.id')->limit(51)
            ->get(['c.id', 'c.uuid', 'c.team_only', 'c.author_type as actor_type', 'c.author_user_id as actor_user_id', 'c.project_client_id', 'p.id as project_id', 'p.uuid as project_uuid', 'p.title as project', 't.uuid as task_uuid', 't.title as task', DB::raw("CASE WHEN c.team_only = 1 THEN '' ELSE SUBSTR(c.text, 1, 220) END as preview"), DB::raw('COALESCE(u.name, pc.name) as author'), DB::raw('COALESCE(u.email, pc.email) as actor_email')]);
        $settings = $history->isEmpty() && $comments->isEmpty() ? collect() : ProjectNotificationSetting::whereIn('project_id', $history->pluck('project_id')->merge($comments->pluck('project_id'))->unique())->get()->keyBy('project_id');
        $items = [];
        foreach ([...$history, ...$comments] as $row) {
            $comment = property_exists($row, 'preview');
            $kind = $comment ? 'comment' : (in_array($row->event_type, ['status', 'approved'], true) ? 'status' : $row->event_type);
            $self = $row->actor_type === (TaskAccess::isAdmin($request) ? 'admin' : 'client') && ($row->actor_user_id ?? $row->project_client_id) === $recipient->id;
            if (! in_array($kind, ['created', 'status', 'comment'], true) || $self || ($row->actor_email && $recipient->email && strcasecmp($row->actor_email, $recipient->email) === 0)) {
                continue;
            }
            $team = $comment && $row->team_only;
            $event = $team ? 'team.message_created' : ($kind === 'created' ? 'client.task_created' : ($comment ? $row->actor_type.($row->task_uuid ? '.comment_created' : '.project_comment_created') : NotificationEvents::fromHistory($row->actor_type, $row->event_type, $row->old_value, $row->new_value)));
            if (! $this->allowed($settings->get($row->project_id), $recipient, $event)) {
                continue;
            }
            $base = (TaskAccess::isAdmin($request) ? '/admin/projects/' : '/project/').$row->project_uuid;
            $url = $row->task_uuid ? $base.(TaskAccess::isAdmin($request) ? '/brief/task/' : '/task/').$row->task_uuid : $base.'/discussions';
            $items[] = ['id' => ($comment ? 'comment:' : 'history:').$row->id, 'kind' => $kind, 'project' => $this->text($row->project, 100), 'task' => $this->text($row->task ?? '', 100),
                'author' => $this->text($row->author ?? '', 60), 'preview' => $comment ? $this->text($row->preview, 160) : '', 'old' => $row->old_value ?? null, 'status' => ($row->event_type ?? null) === 'approved' ? 'approved' : ($row->new_value ?? null),
                'url' => $url.($comment ? ($team ? '#team-' : '#comment-').$row->uuid : '')];
        }

        return ['watermark' => [...$latest, 'startHistory' => $watermark['startHistory'] ?? $watermark['history'], 'startComments' => $watermark['startComments'] ?? $watermark['comments']], 'items' => array_slice($items, 0, 100), 'additional' => 0, 'overflow' => $history->count() > 50 || $comments->count() > 50];
    }

    private function text(string $text, int $limit): string
    {
        return NotificationPayload::excerpt(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')))), $limit);
    }

    private function allowed(?ProjectNotificationSetting $settings, User|ProjectClient $recipient, ?string $event): bool
    {
        if (! $recipient->notificationPreferences()['push']) {
            return false;
        }
        if (! $settings) {
            return true;
        }
        $rules = $settings->rules();
        $candidates = $event ? [$event => $rules[$event] ?? null] : $rules;
        foreach ($candidates as $code => $rule) {
            if (! $rule || ! $rule['enabled']) {
                continue;
            }
            if ($settings->delivery_mode !== 'personal' && ! ($settings->preferences()[$code]['push'] ?? false)) {
                continue;
            }
            $key = $recipient instanceof ProjectClient ? $recipient->uuid : $recipient->id;
            if ($settings->delivery_mode !== 'personal' || $rule['recipients']['mode'] !== 'selected' || in_array($key, $rule['recipients']['ids'], true)) {
                return true;
            }
        }

        return false;
    }
}
