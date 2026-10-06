<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientLocaleController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['locale'])) {
            throw ValidationException::withMessages(['locale' => 'Only locale may be changed.']);
        }
        $data = $request->validate(['locale' => ['required', 'string', Rule::in(['ru', 'en'])]]);
        $client = $request->attributes->get('project_client');
        $client->update(['preferred_locale' => $data['locale']]);

        return response()->json(['preferredLocale' => $client->preferred_locale]);
    }
}
