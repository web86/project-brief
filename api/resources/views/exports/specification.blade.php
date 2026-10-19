<!doctype html>
<html lang="{{ $document['generated']['locale'] }}">
<head>
<meta charset="utf-8">
<style>
@page { margin: 22mm 20mm 25mm; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; line-height: 1.5; color: #243044; }
h1 { font-size: 20pt; line-height: 1.3; margin-bottom: 24pt; }
h2 { font-size: 16pt; margin-top: 24pt; page-break-after: avoid; }
h3 { font-size: 12pt; margin-top: 18pt; page-break-after: avoid; }
h4 { font-size: 10pt; margin-bottom: 4pt; page-break-after: avoid; }
p { margin: 0 0 10pt; overflow-wrap: break-word; }
.content { white-space: pre-wrap; }
footer { position: fixed; bottom: -16mm; color: #667085; font-size: 8pt; }
</style>
</head>
<body>
<footer>{{ $document['labels']['footer'] }} · {{ $document['generated']['date'] }}</footer>
<h1>{{ $document['labels']['title'] }}</h1>
<p><strong>{{ $document['labels']['project'] }}:</strong> {{ $document['project']['name'] }}</p>
@if ($document['project']['website'])
<p>{{ $document['labels']['website'] }}: {{ $document['project']['website'] }}</p>
@endif
<p>{{ $document['labels']['generated'] }}: {{ $document['generated']['date'] }}</p>
@foreach ($document['sections'] as $section)
<h2>{{ $section['number'] }}. {{ $section['name'] }}</h2>
@foreach ($section['tasks'] as $task)
<h3>{{ $task['number'] }} {{ $task['title'] }}</h3>
@foreach ($task['content'] as $field)
@if ($field['block'])
<h4>{{ $field['label'] }}</h4>
<p class="content">{{ $field['value'] }}</p>
@else
<p><strong>{{ $field['label'] }}:</strong> {{ $field['value'] }}</p>
@endif
@endforeach
@endforeach
@endforeach
</body>
</html>
