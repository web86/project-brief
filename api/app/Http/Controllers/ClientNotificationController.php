<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientNotificationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $client = $request->attributes->get('project_client');

        return response()->json(['preferences' => $client->notificationPreferences(), 'email' => $client->email])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['preferences']), 422);
        $data = $request->validate(['preferences' => ['required', 'array:email,push,telegram'], 'preferences.email' => ['required', 'boolean'], 'preferences.push' => ['required', 'boolean'], 'preferences.telegram' => ['required', 'boolean']]);
        $request->attributes->get('project_client')->update(['notification_preferences' => $data['preferences']]);

        return $this->show($request);
    }
}
