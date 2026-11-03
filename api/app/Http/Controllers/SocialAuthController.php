<?php

namespace App\Http\Controllers;

use App\Services\SocialAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SocialAuthController extends Controller
{
    public function start(Request $request, string $provider, SocialAuth $social): JsonResponse
    {
        abort_if($request->all(), 422);
        $intent = $request->route()->defaults['socialIntent'] ?? 'login';

        return response()->json(['url' => $social->start($request, $provider, $intent)])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, string $provider, SocialAuth $social): RedirectResponse
    {
        try {
            $result = $social->callback($request, $provider, is_string($request->query('state')) ? $request->query('state') : '', is_string($request->query('code')) && ! $request->has('error') ? $request->query('code') : null);
            $path = $result['path'];
        } catch (\Throwable $e) {
            $allowed = ['social_account_link_required', 'social_identity_already_linked', 'social_attempt_invalid', 'social_cancelled', 'registration_disabled', 'social_account_unavailable'];
            $reason = $e instanceof HttpExceptionInterface && in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'provider_unavailable';
            $path = ($request->user() ? $social->connectionsPath($request) : '/admin/login').'?authError='.$reason;
        }

        return redirect($path, 303)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function email(Request $request, SocialAuth $social): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        return response()->json($social->supplyEmail($request, $data['email']))->header('Cache-Control', 'no-store');
    }

    public function connections(Request $request, SocialAuth $social): JsonResponse
    {
        abort_unless($request->user(), 401);

        return response()->json($social->connections($request->user()->fresh()))->header('Cache-Control', 'no-store');
    }

    public function disconnect(Request $request, string $provider, SocialAuth $social): JsonResponse
    {
        $social->disconnect($request, $provider);

        return response()->json(['disconnected' => true])->header('Cache-Control', 'no-store');
    }
}
