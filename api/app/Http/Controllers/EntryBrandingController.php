<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\CurrentWorkspace;
use App\Services\DeveloperInvitations;
use App\Services\WorkspaceBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EntryBrandingController extends Controller
{
    public function __invoke(Request $request, DeveloperInvitations $invitations, WorkspaceBranding $branding): JsonResponse
    {
        $workspace = null;
        if ($request->session()->has('invitation_context')) {
            try {
                $workspace = $invitations->resolveRequest($request)->workspace;
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 410) {
                    throw $exception;
                }
                $request->session()->forget('invitation_context');
            }
        } elseif ($request->user()) {
            $members = $request->user()->workspaceMembers()->active()->with('workspace')->get();
            $workspace = $members->firstWhere('workspace_id', $request->session()->get(CurrentWorkspace::SESSION_KEY))?->workspace
                ?? ($members->count() === 1 ? $members->first()->workspace : null);
        } elseif ($request->session()->get('client_project_id')) {
            $project = Project::whereKey($request->session()->get('client_project_id'))->where('status', 'active')->first();
            $client = $project?->clients()->whereKey($request->session()->get('project_client_id'))->where('active', true)->first();
            $token = $client?->accessTokens()->whereKey($request->session()->get('client_access_token_id'))->where('project_id', $project->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
            $workspace = $token && (! $request->filled('project') || $request->input('project') === $project->uuid) ? $project->workspace : null;
        }

        return response()->json(['branding' => $workspace ? $branding->portal($workspace) : null])->header('Cache-Control', 'no-store');
    }
}
