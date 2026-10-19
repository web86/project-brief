<!doctype html>
<html lang="{{ $document['generated']['locale'] }}">
<head>
<meta charset="utf-8">
<style>
@page { margin: 20mm 20mm 22mm; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; line-height: 1.35; color: #243044; }
h1 { font-size: 20pt; line-height: 1.2; margin: 0 0 14pt; }
h2 { font-size: 14pt; margin: 16pt 0 7pt; page-break-after: avoid; }
h3 { font-size: 11.5pt; margin: 11pt 0 5pt; page-break-after: avoid; }
h4 { font-size: 9.5pt; margin: 5pt 0 2pt; page-break-after: avoid; }
p { margin: 0 0 5pt; overflow-wrap: break-word; }
.content { white-space: pre-wrap; }
.project-meta { font-size: 9pt; margin-bottom: 3pt; }
.task-metadata { font-size: 8.5pt; color: #586174; line-height: 1.3; margin: 5pt 0 8pt; page-break-before: avoid; page-break-inside: avoid; }
.attachments { font-size: 9pt; margin: 2pt 0 5pt; padding-left: 16pt; }
.section-page-break { page-break-before: always; }
.summary { page-break-inside: avoid; margin-top: 16pt; padding-top: 5pt; border-top: 0.5pt solid #d0d5dd; }
.summary h2 { font-size: 12pt; margin: 4pt 0 6pt; }
.summary p { font-size: 9.5pt; margin-bottom: 3pt; }
.summary .coverage { font-size: 8.5pt; color: #586174; margin-bottom: 5pt; }
footer { position: fixed; bottom: -14mm; color: #667085; font-size: 7.5pt; }
</style>
</head>
<body>
<footer>{{ $document['labels']['footer'] }} · {{ $document['generated']['date'] }}</footer>
<h1>{{ $document['title'] }}</h1>
<p class="project-meta"><strong>{{ $document['labels']['project'] }}:</strong> {{ $document['project']['name'] }}</p>
@if ($document['project']['website'])
<p class="project-meta">{{ $document['labels']['website'] }}: {{ $document['project']['website'] }}</p>
@endif
<p class="project-meta">{{ $document['labels']['generated'] }}: {{ $document['generated']['date'] }}</p>
@foreach ($document['sections'] as $section)
<h2 @class(['section-page-break' => $document['presentation']['pageBreakBySection'] && ! $loop->first])>{{ $section['number'] }}. {{ $section['name'] }}</h2>
@foreach ($section['tasks'] as $task)
<h3>{{ $task['number'] }} {{ $task['title'] }}</h3>
@foreach ($task['content'] as $field)
@if ($field['block'])
<h4>{{ $field['label'] }}</h4>
<p class="content">{{ $field['value'] }}</p>
@endif
@endforeach
@if ($task['attachments'])
<h4>{{ $document['labels']['attachments'] }}</h4>
<ul class="attachments">
@foreach ($task['attachments'] as $filename)
<li>{{ $filename }}</li>
@endforeach
</ul>
@endif
@if ($task['metadata'] !== '')
<p class="task-metadata">{{ $task['metadata'] }}</p>
@endif
@endforeach
@endforeach
<div class="summary">
<h2>{{ $document['labels']['summary'] }}</h2>
@foreach ($document['summary'] as $row)
<p><strong>{{ $row['label'] }}:</strong> {{ $row['value'] }}</p>
@if ($row['coverage'])
<p class="coverage">{{ $row['coverage'] }}</p>
@endif
@endforeach
</div>
</body>
</html>
