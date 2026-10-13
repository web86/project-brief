<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectUnread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectActivityController extends Controller
{
    public function show(Request $request, Project $project, ProjectUnread $unread): JsonResponse
    {
        return response()->json($unread->state($request, $project))->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, Project $project, ProjectUnread $unread): JsonResponse
    {
        abort_if(count($request->all()) > 0, 422, 'Read markers are determined by the server.');

        return response()->json($unread->mark($request, $project))->header('Cache-Control', 'no-store');
    }
}
