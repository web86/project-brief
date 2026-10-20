<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\NotificationEvents;
use App\Services\ProjectAccess;
use App\Services\ProjectNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationSettingsController extends Controller
{
    public function show(Project $project, ProjectNotificationService $service): JsonResponse
    {
        $settings = $service->settings($project);

        return response()->json(['events' => $settings->preferences(), 'notificationEmail' => $settings->notification_email, 'fallbackEmail' => $service->primaryOwner($project)?->email, 'emailAvailable' => $service->mailAvailable(), 'deliveryMode' => $settings->delivery_mode, 'eventRules' => $settings->rules(), 'recipientOptions' => $this->recipientOptions($project)])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Project $project, ProjectNotificationService $service): JsonResponse
    {
        if ($request->has('eventRules') || $request->has('deliveryMode')) {
            return $this->updateRules($request, $project, $service);
        }
        abort_unless($service->settings($project)->delivery_mode === 'legacy', 409, 'Project uses personal notification channels.');
        $rules = ['notificationEmail' => ['nullable', 'email', 'max:255'], 'events' => ['required', 'array']];
        // Literal dots in event codes must be escaped for Laravel's validation paths.
        foreach (NotificationEvents::CODES as $event) {
            $path = 'events.'.str_replace('.', '\\.', $event);
            $rules[$path] = [in_array($event, NotificationEvents::TASK_CODES, true) ? 'required' : 'sometimes', 'array:email,push,telegram'];
            $rules[$path.'.email'] = ['required_with:'.$path, 'boolean'];
            $rules[$path.'.push'] = ['required_with:'.$path, 'boolean'];
            $rules[$path.'.telegram'] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);
        if (array_key_exists('notificationEmail', $data) && $data['notificationEmail'] !== $service->settings($project)->notification_email) {
            throw ValidationException::withMessages(['notificationEmail' => 'Change and verify your address in personal notification settings.']);
        }
        if (array_diff(array_keys($request->input('events')), NotificationEvents::CODES)) {
            throw ValidationException::withMessages(['events' => 'Unknown notification event.']);
        }
        $attributes = ['events' => array_replace_recursive($service->settings($project)->preferences(), $data['events'])];
        $service->settings($project)->update($attributes);

        return $this->show($project, $service);
    }

    private function recipientOptions(Project $project): array
    {
        return [
            'team' => ProjectAccess::recipients($project)->get()->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->all(),
            'clients' => $project->clients()->where('active', true)->whereHas('accessTokens', fn ($query) => $query->where('project_id', $project->id)->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))
                ->get()->map(fn ($client) => ['id' => $client->uuid, 'name' => $client->name])->all(),
        ];
    }

    private function updateRules(Request $request, Project $project, ProjectNotificationService $service): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['deliveryMode', 'eventRules', 'confirmMigration']), 422);
        $settings = $service->settings($project);
        $rules = ['deliveryMode' => ['required', 'in:personal'], 'eventRules' => ['required', 'array'], 'confirmMigration' => $settings->delivery_mode === 'legacy' ? ['required', 'accepted'] : ['sometimes', 'boolean']];
        foreach (NotificationEvents::CODES as $event) {
            $path = 'eventRules.'.str_replace('.', '\\.', $event);
            $rules[$path] = ['required', 'array:enabled,recipients'];
            $rules[$path.'.enabled'] = ['required', 'boolean'];
            $rules[$path.'.recipients'] = ['required', 'array:mode,ids'];
            $rules[$path.'.recipients.mode'] = ['required', 'in:all,selected'];
            $rules[$path.'.recipients.ids'] = ['present', 'array', 'max:500'];
            $rules[$path.'.recipients.ids.*'] = ['required', 'distinct', str_starts_with($event, 'client.') ? 'integer' : 'uuid'];
        }
        $data = $request->validate($rules);
        if (array_diff(array_keys($data['eventRules']), NotificationEvents::CODES)) {
            throw ValidationException::withMessages(['eventRules' => 'Unknown notification event.']);
        }
        $options = $this->recipientOptions($project);
        foreach ($data['eventRules'] as $event => &$rule) {
            $team = str_starts_with($event, 'client.');
            $ids = array_map($team ? fn ($id) => (int) $id : fn ($id) => mb_strtolower($id), $rule['recipients']['ids']);
            if (array_diff($ids, array_column($options[$team ? 'team' : 'clients'], 'id'))) {
                throw ValidationException::withMessages(['eventRules' => 'Recipient does not have access to this project.']);
            }
            $rule['enabled'] = (bool) $rule['enabled'];
            $rule['recipients']['ids'] = $rule['recipients']['mode'] === 'all' ? [] : $ids;
        }
        unset($rule);
        $settings->update(['delivery_mode' => 'personal', 'event_rules' => $data['eventRules']]);

        return $this->show($project, $service);
    }

    public function test(Request $request, Project $project, ProjectNotificationService $service): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', 'in:email,push'], 'endpoint' => ['nullable', 'string', 'max:4096']]);
        $result = $service->testDelivery($project, $request->user(), $data['channel'], $data['endpoint'] ?? null);

        return response()->json(['status' => $result['status'], 'code' => $result['error_code']], $result['status'] === 'sent' ? 200 : 422);
    }
}
