<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\PushEndpoint;
use App\Services\TaskAccess;
use App\Services\WebPushTransport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    private function owned(Request $request): Builder
    {
        return PushSubscription::query()->where(TaskAccess::isAdmin($request) ? 'user_id' : 'project_client_id', TaskAccess::isAdmin($request) ? $request->user()->id : $request->attributes->get('project_client')->id);
    }

    public function status(Request $request, WebPushTransport $transport): JsonResponse
    {
        $hash = $request->query('endpointHash');
        $subscribed = is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) && $this->owned($request)->where('endpoint_hash', $hash)->exists();

        return response()->json(['available' => $transport->available(), 'publicKey' => $transport->available() ? config('project_notifications.vapid.public_key') : null, 'subscribed' => $subscribed])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, WebPushTransport $transport): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:4096', fn ($attribute, $value, $fail) => PushEndpoint::valid($value) ? null : $fail('Unsupported push endpoint.')],
            'keys' => ['required', 'array:p256dh,auth'], 'keys.p256dh' => ['required', 'string', 'regex:/^B[A-Za-z0-9_-]{86}$/'], 'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22}$/'],
            'contentEncoding' => ['nullable', 'in:aes128gcm'], 'user_id' => ['prohibited'], 'project_client_id' => ['prohibited']]);
        if (! $transport->available()) {
            return response()->json(['code' => 'push_unconfigured'], 422);
        }
        $hash = hash('sha256', $data['endpoint']);
        $existing = PushSubscription::where('endpoint_hash', $hash)->first();
        // Explicit enabling in the current session binds this browser to its current persona.
        // Never read or mutate another owner's record through this endpoint.
        if ($existing && ! $this->owned($request)->whereKey($existing->id)->exists()) {
            return response()->json(['code' => 'device_owned_elsewhere'], 409);
        }
        $owner = TaskAccess::isAdmin($request) ? ['user_id' => $request->user()->id, 'project_client_id' => null] : ['user_id' => null, 'project_client_id' => $request->attributes->get('project_client')->id];
        $subscription = $existing ?? new PushSubscription;
        $subscription->fill([...$owner, 'endpoint' => $data['endpoint'], 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'], 'content_encoding' => 'aes128gcm', 'user_agent' => substr($request->userAgent() ?? '', 0, 500)])->save();

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:4096'], 'user_id' => ['prohibited'], 'project_client_id' => ['prohibited']]);
        $this->owned($request)->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['subscribed' => false]);
    }
}
