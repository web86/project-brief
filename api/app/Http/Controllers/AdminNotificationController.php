<?php

namespace App\Http\Controllers;

use App\Services\AccountProfile;
use App\Services\CurrentWorkspace;
use App\Services\PersonalNotifications;
use App\Services\ProjectNotificationService;
use App\Services\ProjectPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    public function show(Request $request, PersonalNotifications $personal, ProjectNotificationService $delivery): JsonResponse
    {
        $user = $request->user()->fresh();
        $memberships = $user->workspaceMembers()->active()->with('workspace')->orderBy('workspace_id')->get();
        $selected = $request->session()->get(CurrentWorkspace::SESSION_KEY);
        $member = $request->session()->has(CurrentWorkspace::SESSION_KEY)
            ? (is_int($selected) && $selected > 0 ? $memberships->firstWhere('workspace_id', $selected) : null)
            : ($memberships->count() === 1 ? $memberships->first() : null);

        return response()->json([
            'user' => [...app(AccountProfile::class)->state($user), 'isProjectSuperadmin' => (bool) $member?->is_project_superadmin, 'membershipRole' => $member?->role, 'canCreateProjects' => $member ? ProjectPermissions::canCreateProjects($member) : false],
            'workspace' => $member ? app(CurrentWorkspace::class)->state($member, true) : null,
            'workspaces' => $memberships->map(fn ($member) => ['id' => $member->workspace->uuid, 'name' => $member->workspace->name])->values(),
            'preferences' => $user->notificationPreferences(), 'email' => $user->notificationEmail(), 'accountEmail' => $user->email,
            'emailUnified' => $user->notification_email_resolved_at !== null, 'pendingEmail' => $user->pending_notification_email,
            'verificationExpiresAt' => $user->notification_email_expires_at?->toISOString(), 'emailAvailable' => $delivery->mailAvailable(),
            'legacyEmails' => $personal->legacyEmails($user),
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, PersonalNotifications $personal, ProjectNotificationService $delivery): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['preferences', 'email']), 422);
        $data = $request->validate([
            'preferences' => ['sometimes', 'required', 'array:email,push,telegram'],
            'preferences.email' => ['required_with:preferences', 'boolean'], 'preferences.push' => ['required_with:preferences', 'boolean'], 'preferences.telegram' => ['required_with:preferences', 'boolean'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:254'],
        ]);
        $user = $request->user();
        if (isset($data['preferences'])) {
            $user->forceFill(['notification_preferences' => $data['preferences']])->save();
        }
        $result = isset($data['email']) ? $personal->changeEmail($user, $data['email'], $delivery) : ['status' => 'saved', 'error_code' => null];
        if ($result['status'] === 'failed') {
            return response()->json(['code' => $result['error_code']], 422);
        }

        return $this->show($request, $personal, $delivery)->withHeaders(['X-Notification-Email-Status' => $result['status']]);
    }

    public function verify(Request $request, PersonalNotifications $personal, ProjectNotificationService $delivery): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['token']), 422);
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        if (! $personal->verify($request->user(), $data['token'])) {
            return response()->json(['code' => 'email_verification_invalid'], 422);
        }
        $request->user()->refresh();

        return $this->show($request, $personal, $delivery);
    }

    public function cancel(Request $request, PersonalNotifications $personal, ProjectNotificationService $delivery): JsonResponse
    {
        abort_if($request->all(), 422);
        $request->user()->forceFill($personal->clearPending())->save();

        return $this->show($request, $personal, $delivery);
    }

    public function test(Request $request, ProjectNotificationService $delivery): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['channel', 'endpoint']), 422);
        $data = $request->validate(['channel' => ['required', 'in:email,push'], 'endpoint' => ['nullable', 'string', 'max:4096']]);
        $result = $delivery->personalTest($request->user(), $data['channel'], $data['endpoint'] ?? null);

        return response()->json(['status' => $result['status'], 'code' => $result['error_code']], $result['status'] === 'sent' ? 200 : 422);
    }
}
