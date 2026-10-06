<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\NotificationEvents;
use App\Services\ProjectNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationSettingsController extends Controller
{
    public function show(Project $project, ProjectNotificationService $service): JsonResponse
    {
        $settings = $service->settings($project);

        return response()->json(['events' => $settings->preferences(), 'notificationEmail' => $settings->notification_email, 'fallbackEmail' => $service->admin($project)?->email, 'emailAvailable' => $service->mailAvailable()])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Project $project, ProjectNotificationService $service): JsonResponse
    {
        $rules = ['notificationEmail' => ['nullable', 'email', 'max:255'], 'events' => ['required', 'array']];
        // Literal dots in event codes must be escaped for Laravel's validation paths.
        foreach (NotificationEvents::CODES as $event) {
            $path = 'events.'.str_replace('.', '\\.', $event);
            $rules[$path] = ['required', 'array:email,push'];
            $rules[$path.'.email'] = ['required', 'boolean'];
            $rules[$path.'.push'] = ['required', 'boolean'];
        }
        $data = $request->validate($rules);
        if (array_diff(array_keys($request->input('events')), NotificationEvents::CODES)) {
            throw ValidationException::withMessages(['events' => 'Unknown notification event.']);
        }
        $service->settings($project)->update(['notification_email' => $data['notificationEmail'] ?? null, 'events' => $data['events']]);

        return $this->show($project, $service);
    }

    public function test(Request $request, Project $project, ProjectNotificationService $service): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', 'in:email,push'], 'endpoint' => ['nullable', 'string', 'max:4096']]);
        $result = $service->testDelivery($project, $request->user(), $data['channel'], $data['endpoint'] ?? null);

        return response()->json(['status' => $result['status'], 'code' => $result['error_code']], $result['status'] === 'sent' ? 200 : 422);
    }
}
