{{ $heading }}

{{ $messageText }}
@if($actionUrl)
@if($actionLabel)
{{ $actionLabel }}
@endif
{{ $actionUrl }}
@endif

{{ ($branding['name'] ?? '') ?: 'Dev2Done' }}

@if(!empty($branding['contact']))
{{ implode(' · ', $branding['contact']) }}
@endif
