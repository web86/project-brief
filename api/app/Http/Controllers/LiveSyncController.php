<?php

namespace App\Http\Controllers;

use App\Services\LiveSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveSyncController extends Controller
{
    public function __invoke(Request $request, LiveSync $sync): JsonResponse
    {
        return response()->json($sync->changes($request))->header('Cache-Control', 'no-store');
    }
}
