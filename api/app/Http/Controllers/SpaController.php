<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SpaController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): BinaryFileResponse|JsonResponse
    {
        abort_unless(in_array($request->method(), ['GET', 'HEAD'], true), 404);
        abort_if($request->is('api', 'api/*', 'access', 'access/*', 'sanctum', 'sanctum/*'), 404);

        $index = public_path('index.html');
        if (is_file($index)) {
            return response()->file($index, ['Cache-Control' => 'no-store']);
        }

        abort_unless($request->path() === '/', 404);

        return response()->json(['service' => 'ProjectBrief API']);
    }
}
