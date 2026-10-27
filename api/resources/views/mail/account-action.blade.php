<!doctype html><html lang="{{ $language }}"><meta charset="utf-8"><body style="margin:0;padding:24px;background:#f6f8f5;font-family:Arial,sans-serif;color:#20352b"><table role="presentation" style="width:100%;max-width:560px;margin:auto;background:#fff"><tr><td style="padding:28px">
@include('mail.brand-header', ['branding'=>$branding])
<h1 style="font-size:24px">{{ $heading }}</h1><p style="line-height:1.6">{{ $messageText }}</p>
@if($actionUrl)<p><a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 20px;background:{{ $branding['color'] ?? '#246c43' }};color:{{ $branding['onColor'] ?? '#ffffff' }};text-decoration:none;border-radius:6px">{{ $heading }}</a></p>@endif
@include('mail.brand-footer', ['branding'=>$branding])
</td></tr></table></body></html>
