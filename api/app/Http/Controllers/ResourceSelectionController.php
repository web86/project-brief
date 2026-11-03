<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsurePlanAuthority;
use App\Models\Plans\PlanVersion;
use App\Models\Workspace;
use App\Services\AccountSecurity;
use App\Services\CurrentWorkspace;
use App\Services\IntegrationWrites;
use App\Services\Plans\AccountingReview;
use App\Services\Plans\ExecutionBarrier;
use App\Services\Plans\FrozenTransitions;
use App\Services\Plans\PlanInput;
use App\Services\Plans\SubscriptionAssignments;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ResourceSelectionController extends Controller
{
    private function context(Request $request, ?string $workspace): array
    {
        app(AccountSecurity::class)->requireFresh($request);
        $platform = $request->is('api/platform/*');
        if ($platform) {
            abort_unless($request->user()->fresh()->email_verified_at && ! $request->user()->fresh()->security_recovery_expires_at, 403);

            return [Workspace::where('uuid', $workspace)->firstOrFail()->id, true];
        }
        $member = app(CurrentWorkspace::class)->resolve($request);
        abort_unless($member->role === 'owner', 403);

        return [$member->workspace_id, false];
    }

    public function handle(Request $request): JsonResponse
    {
        return $this->execute($request)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function execute(Request $request): JsonResponse
    {
        try {
            $workspace = $request->route('workspace');
            $preview = $request->route('preview');
            [$id, $platform] = $this->context($request, $workspace);
            app(ExecutionBarrier::class)->catchUp($id);
            $service = app(FrozenTransitions::class);
            $kind = $request->route()->defaults['preparationKind'];
            if ($kind === 'versions') {
                return DB::transaction(function () use ($id, $platform, $request, $service): JsonResponse {
                    IntegrationWrites::lockWorkspace($id);
                    $service->authorize($request->user(), $id, $platform);
                    $request->validate(['page' => 'sometimes|integer|min:1']);
                    $versions = PlanVersion::where('state', 'published')->whereIn('access_class', ['free', 'paid'])->whereHas('plan', fn ($q) => $q->where('status', '<>', 'archived')->when(! $platform, fn ($q) => $q->where('visibility', 'public')))->orderByDesc('id')->paginate(100);

                    return response()->json(['data' => $versions->getCollection()->map(fn ($v) => ['id' => $v->uuid, 'number' => $v->number, 'translations' => $v->translations_json]), 'meta' => ['page' => $versions->currentPage(), 'lastPage' => $versions->lastPage()]]);
                });
            }
            if ($kind === 'options') {
                return response()->json(['data' => $service->options($request->user(), $id, $platform)]);
            }
            if ($kind === 'review-list') {
                return DB::transaction(function () use ($id, $platform, $request, $service): JsonResponse {
                    IntegrationWrites::lockWorkspace($id);
                    $service->authorize($request->user(), $id, $platform);

                    return response()->json(['data' => app(AccountingReview::class)->listing($id)]);
                });
            }
            if ($kind === 'rearm') {
                PlanInput::only($request->all(), ['groups']);
                $input = $request->validate(['groups' => ['required', 'array', 'list', 'min:1', 'max:4'], 'groups.*' => ['required', 'string', 'distinct', 'in:agent,api,sso,webhook']]);

                return response()->json(['data' => $service->rearm($request->user(), $id, PlanInput::revision($request), $input['groups'], PlanInput::key($request))]);
            }
            if ($kind === 'approve-paid') {
                PlanInput::only($request->all(), ['serviceDays', 'reason']);
                $data = $request->validate(['serviceDays' => ['required', 'integer', 'min:1', 'max:36600'], 'reason' => ['required', 'regex:/^[a-z][a-z0-9_]{0,59}$/D']]);
                $subscription = app(SubscriptionAssignments::class)->approvePaid($request->user(), $id, $preview, PlanInput::revision($request), (int) $data['serviceDays'], $data['reason'], PlanInput::key($request));

                return response()->json(['data' => $subscription]);
            }
            if (in_array($kind, ['apply-basic', 'restore-paid'], true)) {
                PlanInput::only($request->all(), []);

                return response()->json(['data' => $service->applySelection($request->user(), $id, $preview, PlanInput::revision($request), PlanInput::key($request), $kind === 'restore-paid')]);
            }
            if ($kind === 'read') {
                return response()->json(['data' => $service->read($request->user(), $id, $platform, $preview)]);
            }
            if ($kind === 'create') {
                PlanInput::only($request->all(), ['targetVersionId', 'selection']);
                $data = $request->validate(['targetVersionId' => ['required', 'uuid'], 'selection' => ['required', 'array']]);

                return response()->json(['data' => $service->create($request->user(), $id, $platform, $data['targetVersionId'], $data['selection'], PlanInput::revision($request), PlanInput::key($request))], 201);
            }
            PlanInput::only($request->all(), ['clientId', 'action', 'inputHash', 'reason', 'confirmation']);
            $data = $request->validate(['clientId' => ['required', 'uuid'], 'action' => ['required', 'in:confirm,separate'], 'inputHash' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'reason' => ['required', 'regex:/^[a-z][a-z0-9_]{0,59}$/D'], 'confirmation' => ['required', 'in:ACCOUNTING ONLY']]);

            return response()->json(['data' => app(AccountingReview::class)->review($request->user(), $id, $platform, $data['clientId'], $data['action'], PlanInput::revision($request), $data['inputHash'], $data['reason'], PlanInput::key($request))]);
        } catch (\Throwable $e) {
            if ($e instanceof QueryException && ($e->errorInfo[1] ?? null) === 1020) {
                return response()->json(['code' => 'current_snapshot_refresh_required'], 409);
            }
            if ($e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), [409, 422], true) && preg_match('/^[a-z_]{1,80}$/D', $e->getMessage())) {
                return response()->json(['code' => $e->getMessage()], $e->getStatusCode())->header('Cache-Control', 'no-store');
            }

            return EnsurePlanAuthority::failure($e);
        }
    }
}
