{{ $heading }}

{{ $messageText }}
@if($actionUrl)
@if($actionLabel)
{{ $actionLabel }}
@endif
{{ $actionUrl }}
@endif

{{ ($branding['name'] ?? '') ?: 'Project Brief' }}

@if(!empty($branding['contact']))
{{ implode(' · ', $branding['contact']) }}
@endif
