<!doctype html>
<html lang="{{ $language }}"><meta charset="utf-8"><body style="font-family:Arial,sans-serif;line-height:1.6;padding:24px">
<h1>{{ trans('notifications.verify_heading', [], $language) }}</h1>
<p>{{ trans('notifications.verify_message', [], $language) }}</p>
<p><a href="{{ $verificationUrl }}" style="color:#5b4bd6">{{ trans('notifications.verify_action', [], $language) }}</a></p>
<p>{{ trans('notifications.verify_ignore', [], $language) }}</p>
</body></html>
