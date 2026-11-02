@if(!empty($branding['logoUrl']))
<p style="margin:0 0 16px"><img src="{{ $branding['logoUrl'] }}" alt="{{ $branding['name'] ?: 'Dev2Done' }}" style="display:block;max-width:180px;max-height:64px;width:auto;height:auto;border:0"></p>
@endif
<p style="margin:0 0 24px;color:{{ $branding['textColor'] ?? '#5b4bd6' }};font-weight:bold">{{ ($branding['name'] ?? '') ?: 'Dev2Done' }}</p>
