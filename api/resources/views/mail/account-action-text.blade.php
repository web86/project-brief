{{ $heading }}

{{ $messageText }}
@if($actionUrl)
{{ $actionUrl }}
@endif

{{ ($branding['name'] ?? '') ?: 'Project Brief' }}

@if(!empty($branding['contact']))
{{ implode(' · ', $branding['contact']) }}
@endif
