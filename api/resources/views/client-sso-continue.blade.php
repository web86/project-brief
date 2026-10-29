<!doctype html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>{{ trans('interface.sso_continue') }}</title></head>
<body><script nonce="{{ $nonce }}">location.replace('/client-sso/complete')</script><noscript><form method="get" action="/client-sso/complete"><button type="submit">{{ trans('interface.sso_continue') }}</button></form></noscript></body></html>
