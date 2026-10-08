ProjectBrief
{!! $payload['heading'] !!}

{!! $payload['explanation'] !!}
@if($payload['message'] !== null && $payload['message'] !== '')

{!! $payload['message_label'] !!}:
{!! $payload['message'] !!}
@endif

{!! $payload['project_label'] !!}: {!! $payload['project'] !!}
@if($payload['task_title'])
{!! $payload['task_label'] !!}: #{!! $payload['number'] !!} {!! $payload['task_title'] !!}
@endif

{!! $payload['next'] !!}

{!! $payload['open'] !!}: {!! $payload['url'] !!}
{!! $payload['access'] !!}
