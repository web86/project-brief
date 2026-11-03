<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsurePlanAuthority;
use App\Services\AccountSecurity;
use App\Services\CanaryRegistration;
use App\Services\Plans\CommercialCanary;
use App\Services\Plans\PlanInput;
use App\Services\ProjectNotificationService;
use App\Services\RegistrationReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RegistrationDiagnosticsController extends Controller
{
    public function __invoke(RegistrationReadiness $readiness): JsonResponse
    {
        try {
            $targets = $readiness->targets();
        } catch (\Throwable) {
            $targets = null;
        }

        return response()->json(['data' => ['publicRegistration' => $readiness->registrationEnabled(), 'trialActivation' => false, 'lifecycleExecutorReady' => false, 'emailReady' => app(ProjectNotificationService::class)->mailAvailable(), 'providers' => $readiness->publicState()['providers'], 'studioReady' => $targets !== null, 'basicReady' => $targets !== null, 'policyReady' => $targets !== null, 'pendingVerifications' => DB::table('account_onboardings')->where('state', 'email_verification_pending')->count(), 'verifiedUsers' => DB::table('account_onboardings')->whereNotNull('verified_at')->count(), 'claimStates' => DB::table('owner_trial_claims')->select('state')->selectRaw('COUNT(*) AS count')->groupBy('state')->pluck('count', 'state'), 'claimReasons' => DB::table('owner_trial_claims')->whereIn('reason_code', ['activation_unavailable', 'claim_retention_unconfigured', 'verified_subject_previously_claimed', 'verified_subject_conflict', 'legacy_or_imported_workspace'])->select('reason_code')->selectRaw('COUNT(*) AS count')->groupBy('reason_code')->pluck('count', 'reason_code'), 'reasonCodes' => $readiness->commercialBlockers($targets !== null)]])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function canary(Request $request, CommercialCanary $canary, string $operation): Response
    {
        try {
            $actor = $request->user()->fresh();
            abort_unless((int) DB::table('platform_plan_authority')->where('id', 1)->value('primary_user_id') === $actor->id, 403);
            $security = app(AccountSecurity::class);
            abort_unless($actor->email_verified_at && $security->enrolled($actor) && $security->strong($request, $actor) && ! $actor->security_recovery_expires_at, 403);
            $security->requireFresh($request);
            if ($operation === 'invite') {
                PlanInput::only($request->all(), ['email']);
                $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
                $invite = app(CanaryRegistration::class)->issue($actor, $data['email'], PlanInput::key($request));

                return response()->json(['data' => $invite], 201)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
            }
            $revision = PlanInput::revision($request);
            $key = PlanInput::key($request);
            $data = match ($operation) {
                'approve' => $request->validate(['claims' => ['required', 'array', 'list', 'min:1', 'max:10'], 'claims.*' => ['required', 'uuid', 'distinct'], 'evidence' => ['required', 'array']]),
                'admit' => $request->validate(['claimId' => ['required', 'uuid']]),
                default => [],
            };
            PlanInput::only($request->all(), array_keys($data));
            $result = match ($operation) {
                'approve' => $canary->approve($actor, $revision, $data['claims'], $data['evidence'], $key),
                'admit' => $canary->admit($actor, $revision, $data['claimId'], $key),
                'stop' => $canary->stop($actor, $revision, $key),
            };

            return response()->json(['data' => ['revision' => (string) ($operation === 'admit' ? $revision : $result['after']), 'state' => $result['state'] ?? 'admitted']])->header('Cache-Control', 'no-store');
        } catch (\Throwable $error) {
            return EnsurePlanAuthority::failure($error);
        }
    }
}
