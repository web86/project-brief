<?php

namespace App\Http\Controllers;

use App\Services\PlatformOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlatformController extends Controller
{
    public function __construct(private PlatformOverview $platform) {}

    private function since(Request $request): string
    {
        $request->validate(['period' => ['sometimes', Rule::in(['24h', '7d', '30d'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);

        return $this->platform->since($request->input('period', '24h'));
    }

    public function overview(Request $request): JsonResponse
    {
        $since = $this->since($request);
        $workspaces = DB::query()->fromSub($this->platform->workspaces(), 'w')->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN last_activity_at >= ? THEN 1 ELSE 0 END), 0) as active', [$since])->first();

        return response()->json([
            'workspaces' => ['total' => (int) $workspaces->total, 'active' => (int) $workspaces->active, 'inactive' => (int) $workspaces->total - (int) $workspaces->active],
            'owners' => DB::table('workspace_members')->where('role', 'owner')->distinct()->count('user_id'),
            'projects' => DB::table('projects')->whereNull('deleted_at')->count(),
            'clients' => $this->platform->clientCount(),
            'tasks' => DB::table('tasks')->join('projects', 'projects.id', '=', 'tasks.project_id')->whereNull('projects.deleted_at')->whereNull('tasks.deleted_at')->count(),
            'api' => $this->platform->usage($since)->first(),
            'webhooks' => $this->platform->webhooks($since),
            'since' => $since.'Z', 'metricsEnabled' => (bool) config('platform.metrics_enabled'),
        ]);
    }

    public function workspaces(Request $request): JsonResponse
    {
        $this->since($request);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = $this->platform->workspaces();
        $search = trim($request->input('q', ''));
        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('workspaces.name', 'like', '%'.$search.'%')->orWhereExists(function ($owners) use ($search): void {
                    $owners->selectRaw('1')->from('workspace_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->whereColumn('m.workspace_id', 'workspaces.id')->where('m.role', 'owner')->where(fn ($q) => $q->where('u.name', 'like', '%'.$search.'%')->orWhere('u.email', 'like', '%'.$search.'%'));
                });
            });
        }
        $page = $query->orderBy('workspaces.id')->paginate(25);
        $owners = $this->platform->owners($page->getCollection()->pluck('id')->all());
        $page->setCollection($page->getCollection()->map(fn ($workspace) => [...(array) $workspace, 'owners' => $owners[$workspace->id] ?? []]));

        return response()->json($page);
    }

    public function workspace(Request $request, string $uuid): JsonResponse
    {
        $since = $this->since($request);
        $workspace = $this->platform->workspaces()->where('workspaces.uuid', $uuid)->first();
        abort_unless($workspace, 404);
        $id = $workspace->id;
        $projects = DB::table('projects')->where('workspace_id', $id)->whereNull('deleted_at');
        $tokens = DB::table('integration_tokens')->where('workspace_id', $id)->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?) THEN 1 ELSE 0 END), 0) as active', [now()])->first();
        $notifications = DB::table('notification_deliveries as n')->leftJoin('projects as p', 'p.id', '=', 'n.project_id')->leftJoin('project_requests as r', 'r.id', '=', 'n.project_request_id')->where(fn ($q) => $q->where('p.workspace_id', $id)->orWhere('r.workspace_id', $id))->where('n.status', 'sent')->where('n.created_at', '>=', $since)->count();

        return response()->json([
            'workspace' => $workspace, 'owners' => $this->platform->owners([$id])[$id] ?? [],
            'counts' => ['projects' => $projects->count(), 'clients' => $this->platform->clientCount($id), 'members' => DB::table('workspace_members')->where('workspace_id', $id)->where('status', 'active')->count(), 'requests' => DB::table('project_requests')->where('workspace_id', $id)->where('created_at', '>=', $since)->count(), 'notifications' => $notifications],
            'integrations' => DB::table('integration_sources')->where('workspace_id', $id)->get(['id', 'uuid', 'name']),
            'tokens' => ['total' => (int) $tokens->total, 'active' => (int) $tokens->active],
            'webhookEndpoints' => DB::table('webhook_endpoints')->where('workspace_id', $id)->count(),
            'api' => $this->platform->usage($since, $id)->first(), 'webhooks' => $this->platform->webhooks($since, $id),
            'limits' => ['apiPerMinute' => 60, 'aiEnabled' => (bool) config('ai.enabled'), 'aiPerMinute' => (int) config('ai.per_minute'), 'aiPerDay' => (int) config('ai.daily_limit')],
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $since = $this->since($request);
        $request->validate(['workspace' => ['nullable', 'integer', 'min:1']]);
        $workspace = $request->filled('workspace') ? (int) $request->input('workspace') : null;

        return response()->json($this->platform->usage($since, $workspace, true)->orderByDesc('hour')->orderByDesc('requests')->orderBy('dimension_key')->paginate(25));
    }

    public function audit(Request $request): JsonResponse
    {
        $since = $this->since($request);

        return response()->json(DB::table('platform_audit')->where('created_at', '>=', $since)->orderByDesc('id')->paginate(25));
    }
}
