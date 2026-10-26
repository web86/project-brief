<?php

namespace App\Http\Middleware;

use App\Models\ProjectClient;
use App\Services\SupportedLocales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $preferred = $request->user()?->preferred_locale;
        if (! $preferred && $request->hasSession() && $request->session()->has('project_client_id')) {
            $preferred = ProjectClient::whereKey($request->session()->get('project_client_id'))->value('preferred_locale');
        }
        $manual = $request->header('Accept-Language');
        $browser = SupportedLocales::browser($request->getLanguages()[0] ?? null);
        app()->setLocale(SupportedLocales::resolve(in_array($manual, SupportedLocales::codes(), true) ? $manual : ($preferred ?? $browser)));

        return $next($request);
    }
}
