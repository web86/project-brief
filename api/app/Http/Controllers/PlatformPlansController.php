<?php

namespace App\Http\Controllers;

use App\Models\Plans\Plan;
use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Services\Plans\EntitlementRegistry;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlanInput;
use App\Services\Plans\PlanManagement;
use App\Services\Plans\PlatformPolicyCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformPlansController extends Controller
{
    public function access(Request $request): JsonResponse
    {
        return response()->json(['canManage' => DB::table('platform_plan_authority')->where('id', 1)->where('primary_user_id', $request->user()->id)->exists() && $request->user()->email_verified_at !== null && $request->user()->security_recovery_expires_at === null]);
    }

    public function definitions(): JsonResponse
    {
        return response()->json(['revision' => (string) EntitlementRegistry::REVISION, 'data' => array_values(app(EntitlementRegistry::class)->definitions()), 'seatCeiling' => EntitlementRegistry::MAX_SEATS]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:draft,published,archived', 'visibility' => 'nullable|in:public,hidden', 'sort' => 'nullable|in:code,status,newest,order', 'page' => 'nullable|integer|min:1', 'perPage' => 'nullable|integer|min:25|max:100']);
        $query = Plan::with(['versions' => fn ($q) => $q->latest('number')->limit(20)]);
        if ($filters['q'] ?? '') {
            $search = '%'.($filters['q']).'%';
            $query->where(fn ($q) => $q->where('code', 'like', $search)->orWhereHas('versions', fn ($v) => $v->where('translations_json->ru->name', 'like', $search)->orWhere('translations_json->en->name', 'like', $search)->orWhere('translations_json->tr->name', 'like', $search)->orWhere('translations_json->zh-CN->name', 'like', $search)));
        }
        foreach (['status', 'visibility'] as $field) {
            if ($filters[$field] ?? '') {
                $query->where($field, $filters[$field]);
            }
        }
        $sort = ['code' => 'code', 'status' => 'status', 'newest' => 'created_at', 'order' => 'sort_order'][$filters['sort'] ?? 'order'];
        $paginator = $query->orderBy($sort, $sort === 'created_at' ? 'desc' : 'asc')->orderBy('id')->paginate($filters['perPage'] ?? 25);

        return response()->json(['data' => $paginator->getCollection()->map(fn ($p) => $this->planResource($p)), 'meta' => ['page' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage(), 'total' => $paginator->total()]]);
    }

    public function store(Request $request, PlanCatalog $catalog, PlanInput $input): JsonResponse
    {
        $data = $request->all();
        $code = $data['code'] ?? '';
        unset($data['code']);
        $version = $catalog->create($request->user(), $code, $input->configuration($data), PlanInput::key($request));

        return response()->json(['data' => $input->version($version)], 201);
    }

    public function show(Request $request, string $plan): JsonResponse
    {
        $model = $this->plan($plan);
        $request->validate(['page' => 'nullable|integer|min:1']);
        $versions = $model->versions()->latest('number')->paginate(20);
        $model->setRelation('versions', $versions->getCollection());
        $model->setAttribute('has_draft', $model->versions()->where('state', 'draft')->exists());

        return response()->json(['data' => $this->planResource($model), 'meta' => ['page' => $versions->currentPage(), 'lastPage' => $versions->lastPage()]]);
    }

    public function metadata(Request $request, string $plan, PlanManagement $management): JsonResponse
    {
        $model = $management->metadata($request->user(), $this->plan($plan), PlanInput::revision($request), $request->all(), PlanInput::key($request));

        return response()->json(['data' => $this->planResource($model->load('versions'))]);
    }

    public function draft(Request $request, string $plan, PlanCatalog $catalog, PlanInput $input): JsonResponse
    {
        PlanInput::only($request->all(), ['sourceVersionId']);
        $model = $this->plan($plan);
        $source = $request->filled('sourceVersionId') ? $model->versions()->where('uuid', $request->input('sourceVersionId'))->where('state', 'published')->firstOrFail() : null;
        $version = $catalog->nextDraft($request->user(), $model->id, PlanInput::revision($request), PlanInput::key($request), $source?->id);

        return response()->json(['data' => $input->version($version)], 201);
    }

    public function version(Request $request, string $plan, string $version, PlanInput $input): JsonResponse
    {
        return response()->json(['data' => $input->version($this->versionModel($plan, $version))]);
    }

    public function saveVersion(Request $request, string $plan, string $version, PlanCatalog $catalog, PlanInput $input): JsonResponse
    {
        $model = $this->versionModel($plan, $version);
        $revision = PlanInput::revision($request);
        $updated = $catalog->revise($request->user(), $model->id, $revision, $input->configuration($request->all()), PlanInput::key($request));

        return response()->json(['data' => $input->version($updated)]);
    }

    public function preview(Request $request, string $plan, string $version, PlanManagement $management): JsonResponse
    {
        PlanInput::only($request->all(), ['policy']);
        abort_unless($request->input('policy') === 'keep_existing', 422);
        $preview = $management->preview($request->user(), $this->versionModel($plan, $version), PlanInput::revision($request), PlanInput::key($request));

        return response()->json(['data' => $this->previewResource($preview)], 202);
    }

    public function publish(Request $request, string $plan, string $version, PlanManagement $management, PlanInput $input): JsonResponse
    {
        PlanInput::only($request->all(), ['policy', 'previewId', 'contentHash']);
        $request->validate(['policy' => 'required|in:keep_existing', 'previewId' => 'required|uuid', 'contentHash' => 'required|string|size:64']);
        $published = $management->publish($request->user(), $this->versionModel($plan, $version), PlanInput::revision($request), $request->input('previewId'), $request->input('contentHash'), PlanInput::key($request));

        return response()->json(['data' => $input->version($published)]);
    }

    public function archive(Request $request, string $plan, PlanCatalog $catalog, PlanManagement $management): JsonResponse
    {
        PlanInput::only($request->all(), []);
        $model = $this->plan($plan);
        $revision = PlanInput::revision($request);
        $key = PlanInput::key($request);
        $updated = str_ends_with($request->path(), '/unarchive') ? $management->unarchive($request->user(), $model, $revision, $key) : $catalog->archive($request->user(), $model->id, $revision, $key);

        return response()->json(['data' => $this->planResource($updated->load('versions'))]);
    }

    public function history(Request $request, string $plan): JsonResponse
    {
        $model = $this->plan($plan);
        $request->validate(['page' => 'nullable|integer|min:1']);
        $ids = $model->versions()->pluck('uuid')->push($model->uuid);
        $rows = DB::table('plan_events')->whereIn('aggregate_uuid', $ids)->orderByDesc('id')->paginate(25, ['uuid', 'action', 'result', 'before_revision', 'after_revision', 'created_at']);

        return response()->json(['data' => $rows->items(), 'meta' => ['page' => $rows->currentPage(), 'lastPage' => $rows->lastPage()]]);
    }

    public function settings(Request $request, PlanInput $input): JsonResponse
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $policies = PlatformPolicyVersion::latest('number')->paginate(25);
        $versions = PlanVersion::whereIn('id', $policies->getCollection()->pluck('trial_version_id')->merge($policies->getCollection()->pluck('fallback_version_id')))->pluck('uuid', 'id')->all();
        $settings = DB::table('plan_settings')->where('id', 1)->first();
        $currentUuid = $settings?->current_policy_version_id ? PlatformPolicyVersion::whereKey($settings->current_policy_version_id)->value('uuid') : null;

        return response()->json(['data' => $policies->getCollection()->map(fn ($p) => $input->policyResource($p, $versions)), 'currentPolicyId' => $currentUuid, 'rolloutMode' => $settings?->rollout_mode ?? 'off', 'meta' => ['page' => $policies->currentPage(), 'lastPage' => $policies->lastPage()], 'supportedPolicy' => ['eligibility' => PlatformPolicyCatalog::eligibility(), 'securityTermination' => ['mode' => 'manual_review'], 'retention' => PlatformPolicyCatalog::retention()]]);
    }

    public function savePolicy(Request $request, PlanInput $input, PlatformPolicyCatalog $catalog, ?string $policy = null): JsonResponse
    {
        $model = $policy === null ? null : PlatformPolicyVersion::where('uuid', $policy)->firstOrFail();
        $updated = $catalog->save($request->user(), $model?->id, $model === null ? null : PlanInput::revision($request), $input->policy($request->all()), PlanInput::key($request));
        $uuids = PlanVersion::whereIn('id', [$updated->trial_version_id, $updated->fallback_version_id])->pluck('uuid', 'id')->all();

        return response()->json(['data' => $input->policyResource($updated, $uuids)], $model === null ? 201 : 200);
    }

    public function policyOperation(Request $request, string $policy, PlanManagement $management, PlanInput $input): JsonResponse
    {
        $model = PlatformPolicyVersion::where('uuid', $policy)->firstOrFail();
        $revision = PlanInput::revision($request);
        if (str_ends_with($request->path(), '/previews')) {
            PlanInput::only($request->all(), ['policy']);
            abort_unless($request->input('policy') === 'keep_existing', 422);

            return response()->json(['data' => $this->previewResource($management->preview($request->user(), $model, $revision, PlanInput::key($request)))], 202);
        }
        PlanInput::only($request->all(), ['policy', 'previewId', 'contentHash']);
        $request->validate(['policy' => 'required|in:keep_existing', 'previewId' => 'required|uuid', 'contentHash' => 'required|string|size:64']);
        $updated = $management->publish($request->user(), $model, $revision, $request->input('previewId'), $request->input('contentHash'), PlanInput::key($request));
        $uuids = PlanVersion::whereIn('id', [$updated->trial_version_id, $updated->fallback_version_id])->pluck('uuid', 'id')->all();

        return response()->json(['data' => $input->policyResource($updated, $uuids)]);
    }

    public function publishedVersions(Request $request): JsonResponse
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $rows = PlanVersion::with('plan')->where('state', 'published')->whereHas('plan', fn ($q) => $q->where('status', '<>', 'archived'))->latest('id')->paginate(100);

        return response()->json(['data' => $rows->getCollection()->map(fn ($v) => ['id' => $v->uuid, 'planId' => $v->plan->uuid, 'number' => $v->number, 'accessClass' => $v->access_class, 'code' => $v->plan->code, 'translations' => $v->translations_json]), 'meta' => ['page' => $rows->currentPage(), 'lastPage' => $rows->lastPage()]]);
    }

    public function rules(Request $request): JsonResponse
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $rows = DB::table('plan_transition_rules')->orderByDesc('id')->paginate(25);
        $ids = collect($rows->items())->pluck('source_version_id')->merge(collect($rows->items())->pluck('target_version_id'));
        $uuids = PlanVersion::whereIn('id', $ids)->pluck('uuid', 'id');

        return response()->json(['data' => collect($rows->items())->map(fn ($r) => ['id' => $r->uuid, 'sourceVersionId' => $uuids[$r->source_version_id], 'targetVersionId' => $uuids[$r->target_version_id], 'initiator' => $r->initiator, 'approval' => $r->approval, 'timing' => $r->timing, 'overagePolicy' => json_decode($r->overage_policy, true), 'enabled' => (bool) $r->enabled, 'revision' => (string) $r->revision]), 'meta' => ['page' => $rows->currentPage(), 'lastPage' => $rows->lastPage()]]);
    }

    public function saveRule(Request $request, PlanCatalog $catalog, ?string $rule = null): JsonResponse
    {
        PlanInput::only($request->all(), ['sourceVersionId', 'targetVersionId', 'initiator', 'approval', 'timing', 'overagePolicy', 'enabled']);
        $source = PlanVersion::where('uuid', $request->input('sourceVersionId'))->firstOrFail();
        $target = PlanVersion::where('uuid', $request->input('targetVersionId'))->firstOrFail();
        $current = $rule === null ? null : DB::table('plan_transition_rules')->where('uuid', $rule)->first();
        abort_if($rule !== null && ! $current, 404);
        abort_if($current && ($current->source_version_id !== $source->id || $current->target_version_id !== $target->id || $current->initiator !== $request->input('initiator')), 409);
        abort_unless($request->input('approval') === 'manual', 422);
        $id = $catalog->transition($request->user(), $source->id, $target->id, ['initiator' => $request->input('initiator'), 'approval' => $request->input('approval'), 'timing' => $request->input('timing'), 'overage_policy' => $request->input('overagePolicy'), 'enabled' => $request->input('enabled')], $current ? PlanInput::revision($request) : null, PlanInput::key($request));

        return response()->json(['data' => ['id' => DB::table('plan_transition_rules')->where('id', $id)->value('uuid')]], $current ? 200 : 201);
    }

    public function copyPreview(Request $request, PlanManagement $management): JsonResponse
    {
        PlanInput::only($request->all(), ['fromVersionId', 'toVersionId']);
        $from = PlanVersion::where('uuid', $request->input('fromVersionId'))->firstOrFail();
        $to = PlanVersion::where('uuid', $request->input('toVersionId'))->firstOrFail();

        return response()->json(['data' => $this->previewResource($management->copyPreview($request->user(), $from, $to, PlanInput::key($request)))], 202);
    }

    public function copyRules(Request $request, PlanManagement $management): JsonResponse
    {
        PlanInput::only($request->all(), ['previewId', 'contentHash']);
        $request->validate(['previewId' => 'required|uuid', 'contentHash' => 'required|string|size:64']);

        return response()->json(['data' => $this->previewResource($management->copyRules($request->user(), $request->input('previewId'), $request->input('contentHash'), PlanInput::key($request)))]);
    }

    public function showPreview(Request $request, string $preview): JsonResponse
    {
        $row = DB::table('plan_previews')->where('uuid', $preview)->where('actor_user_id', $request->user()->id)->first();
        abort_unless($row && in_array($row->kind, ['plan_publish', 'policy_publish', 'rules_copy'], true), 404);

        return response()->json(['data' => $this->previewResource($row)]);
    }

    private function plan(string $uuid): Plan
    {
        return Plan::where('uuid', $uuid)->firstOrFail();
    }

    private function versionModel(string $plan, string $uuid): PlanVersion
    {
        return $this->plan($plan)->versions()->with('plan')->where('uuid', $uuid)->firstOrFail();
    }

    private function previewResource(object $row): array
    {
        return ['id' => $row->uuid, 'state' => $row->state, 'contentHash' => $row->target_hash, 'revision' => (string) $row->target_revision, 'expiresAt' => $row->expires_at, 'summary' => json_decode($row->summary_json, true, 16, JSON_THROW_ON_ERROR)];
    }

    private function planResource(Plan $plan): array
    {
        return ['id' => $plan->uuid, 'code' => $plan->code, 'status' => $plan->status, 'visibility' => $plan->visibility, 'revision' => (string) $plan->revision, 'sortOrder' => $plan->sort_order, 'hasDraft' => $plan->has_draft ?? $plan->versions->contains('state', 'draft'), 'versions' => $plan->versions->map(fn ($v) => ['id' => $v->uuid, 'number' => $v->number, 'state' => $v->state, 'revision' => (string) $v->revision, 'translations' => $v->translations_json, 'price' => $v->price_json, 'current' => $plan->published_version_id === $v->id])];
    }
}
