<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureClientProjectAccess;
use App\Services\AccountSecurity;
use App\Services\CurrentWorkspace;
use App\Services\WorkspaceAgreements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __invoke(Request $request, EnsureClientProjectAccess $clients): JsonResponse
    {
        $securityState = app(AccountSecurity::class)->state($request);
        if (in_array($securityState, ['pending_2fa', 'enrollment_required'], true)) {
            return response()->json(['authenticated' => false, 'state' => $securityState])->header('Cache-Control', 'no-store');
        }
        if ($request->user()?->platform_admin) {
            $member = $request->user()->workspaceMembers()->active()->with('workspace')->where('workspace_id', $request->session()->get(CurrentWorkspace::SESSION_KEY))->first();
            if ($member && app(WorkspaceAgreements::class)->required($member)) {
                return response()->json(['authenticated' => true, 'type' => 'admin', 'agreementRequired' => true])->header('Cache-Control', 'no-store');
            }

            return response()->json(['authenticated' => true, 'type' => 'platform'])->header('Cache-Control', 'no-store');
        }
        if ($request->user()?->workspaceMembers()->active()->exists()) {
            $context = app(CurrentWorkspace::class);
            $member = $context->resolve($request);
            $state = [...(app(WorkspaceAgreements::class)->required($member) ? ['agreementRequired' => true] : []), 'authenticated' => true, 'type' => 'admin', 'securityRecommended' => app(AccountSecurity::class)->recommendationsPending($request->user()), 'workspace' => $context->state($member)];
        } elseif ($access = $clients->resolve($request)) {
            $state = ['authenticated' => true, 'type' => 'client', 'project' => ['id' => $access->project->uuid]];
        } else {
            $state = ['authenticated' => false];
        }

        return response()->json($state)->header('Cache-Control', 'no-store');
    }
}
