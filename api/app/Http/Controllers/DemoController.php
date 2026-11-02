<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DemoEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoController extends Controller
{
    public function status(): JsonResponse
    {
        abort_unless(DemoEnvironment::enabled(), 404);
        DemoEnvironment::assertIsolated();

        return response()->json(['enabled' => true, 'readOnly' => DemoEnvironment::readOnly()])->header('Cache-Control', 'no-store');
    }

    public function enter(Request $request, string $role): JsonResponse
    {
        abort_unless(DemoEnvironment::readOnly() && in_array($role, ['developer', 'client'], true), 404);
        DemoEnvironment::assertIsolated();
        $request->session()->flush();
        $request->session()->regenerate(true);
        $request->session()->put('demo.role', $role);
        $project = Project::where('title', 'Northstar Commerce')->firstOrFail();

        return response()->json(['destination' => $role === 'developer' ? '/admin' : '/project/'.$project->uuid]);
    }
}
