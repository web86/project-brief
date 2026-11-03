<?php

namespace App\Http\Controllers;

use App\Services\ProjectNotificationService;
use App\Services\RegistrationReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RegistrationDiagnosticsController extends Controller
{
    public function __invoke(RegistrationReadiness $readiness): JsonResponse
    {
        try {
            $targets = $readiness->targets();
        } catch (\Throwable) {
            $targets = null;
        }

        return response()->json(['data' => ['publicRegistration' => $readiness->registrationEnabled(), 'trialActivation' => false, 'lifecycleExecutorReady' => false, 'emailReady' => app(ProjectNotificationService::class)->mailAvailable(), 'providers' => $readiness->publicState()['providers'], 'studioReady' => $targets !== null, 'basicReady' => $targets !== null, 'policyReady' => $targets !== null, 'pendingVerifications' => DB::table('account_onboardings')->where('state', 'email_verification_pending')->count(), 'verifiedUsers' => DB::table('account_onboardings')->whereNotNull('verified_at')->count(), 'claimStates' => DB::table('owner_trial_claims')->select('state')->selectRaw('COUNT(*) AS count')->groupBy('state')->pluck('count', 'state'), 'claimReasons' => DB::table('owner_trial_claims')->whereIn('reason_code', ['activation_unavailable', 'claim_retention_unconfigured', 'verified_subject_previously_claimed', 'verified_subject_conflict', 'legacy_or_imported_workspace'])->select('reason_code')->selectRaw('COUNT(*) AS count')->groupBy('reason_code')->pluck('count', 'reason_code'), 'reasonCodes' => ['phase_4b2_lifecycle_required', ...($targets ? [] : ['published_targets_required'])]]])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
