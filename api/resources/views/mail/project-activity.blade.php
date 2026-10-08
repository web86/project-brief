<!doctype html>
<html lang="{{ $payload['locale'] }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f6f8f5;font-family:Arial,sans-serif;color:#20352b">
<table role="presentation" style="width:100%;max-width:560px;margin:auto;background:#fff;border-collapse:collapse">
<tr><td style="padding:28px">
    <p style="margin:0 0 24px;color:#40754e;font-weight:bold">ProjectBrief</p>
    <h1 style="font-size:24px;line-height:1.3;margin:0 0 16px">{{ $payload['heading'] }}</h1>
    <p style="line-height:1.6">{{ $payload['explanation'] }}</p>
    @if($payload['message'] !== null && $payload['message'] !== '')
        <p style="font-size:13px;color:#637467">{{ $payload['message_label'] }}</p>
        <blockquote style="margin:0 0 20px;padding:16px;background:#f6f8f5;border-left:3px solid #40754e;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.6">{{ $payload['message'] }}</blockquote>
    @endif
    <p style="line-height:1.6"><strong>{{ $payload['project_label'] }}:</strong> {{ $payload['project'] }}</p>
    @if($payload['task_title'])
        <p style="line-height:1.6"><strong>{{ $payload['task_label'] }}:</strong> #{{ $payload['number'] }} {{ $payload['task_title'] }}</p>
    @endif
    <p style="line-height:1.6">{{ $payload['next'] }}</p>
    <p style="margin:24px 0"><a href="{{ $payload['url'] }}" style="display:inline-block;padding:12px 20px;background:#246c43;color:#fff;text-decoration:none;border-radius:6px">{{ $payload['open'] }}</a></p>
    <p style="font-size:13px;color:#637467;line-height:1.5">{{ $payload['access'] }}</p>
</td></tr>
</table>
</body>
</html>
