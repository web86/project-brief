<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiTaskAssistantRequest;
use App\Models\Project;
use App\Services\AiTaskAssistant;
use Illuminate\Http\JsonResponse;

class AiTaskAssistantController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(AiTaskAssistantRequest $request, Project $project, AiTaskAssistant $assistant): JsonResponse
    {
        return response()->json($assistant->suggest($project, $request->validated()))
            ->header('Cache-Control', 'private, no-store');
    }
}
