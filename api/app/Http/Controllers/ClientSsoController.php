<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ClientSso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

class ClientSsoController extends Controller
{
    public function store(Request $request, ClientSso $sso): JsonResponse
    {
        $token = $request->attributes->get('integration_token');
        abort_unless(in_array('clients:sso', json_decode($token->scopes, true), true), 403);
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 2048, 413);
        abort_if(array_diff(array_keys($request->all()), ['projectId', 'externalUserId', 'email', 'emailVerified']), 422);
        $input = $request->validate(['projectId' => ['required', 'uuid'], 'externalUserId' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'email' => ['required', 'string', 'email', 'max:255'], 'emailVerified' => ['required', function ($attribute, $value, $fail): void {
                if ($value !== true) {
                    $fail('Verified email is required.');
                }
            }]]);

        return response()->json(['data' => $sso->issue($token, $input)], 201);
    }

    /** This stateless POST must not overwrite an existing Lax/Strict session cookie. */
    public function enter(Request $request, ClientSso $sso): Response|RedirectResponse
    {
        $code = $request->request->get('code');
        $handoff = $sso->handoff($code);
        if ($request->query->count() || strlen($request->getContent()) > 1024 || array_diff(array_keys($request->request->all()), ['code']) || ! $handoff || ! $sso->acceptsOrigin($handoff, $request->header('Origin'))) {
            return $this->failure($handoff);
        }
        $nonce = base64_encode(random_bytes(16));

        return response()->view('client-sso-continue', ['nonce' => $nonce], 200)
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-".$nonce."'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
            ->withCookie(cookie(ClientSso::COOKIE, $code, 1, '/client-sso', null, (bool) config('session.secure'), true, false, 'lax')->withDomain(null));
    }

    public function complete(Request $request, ClientSso $sso): RedirectResponse
    {
        $code = $request->cookie(ClientSso::COOKIE);
        $handoff = $sso->handoff($code);
        $result = is_string($code) && ! $request->query->count() ? $sso->consume($request, $code) : null;
        if (! $result) {
            return $this->failure($handoff);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $access = $result['access'];
        $request->session()->put(['client_project_id' => $access->project_id, 'project_client_id' => $access->project_client_id, 'client_access_token_id' => $access->id]);

        return redirect($result['url'], 303)->withCookie($this->clearedHandoff());
    }

    private function clearedHandoff(): Cookie
    {
        return cookie(ClientSso::COOKIE, '', -1, '/client-sso', null, (bool) config('session.secure'), true, false, 'lax')->withDomain(null);
    }

    private function failure(?object $handoff): RedirectResponse
    {
        $project = $handoff ? Project::where('workspace_id', $handoff->workspace_id)->find($handoff->project_id) : null;
        $url = rtrim(config('projectbrief.frontend_url'), '/').'/access-error?reason=sso'.($project ? '&project='.$project->uuid : '');

        return redirect($url, 303)->withCookie($this->clearedHandoff());
    }
}
