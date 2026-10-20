<!doctype html>
<html lang="{{ $language }}"><meta charset="utf-8"><body style="font-family:Arial,sans-serif;line-height:1.6;padding:24px">
<h1>{{ $language === 'ru' ? 'Подтвердите Email уведомлений' : 'Verify notification email' }}</h1>
<p>{{ $language === 'ru' ? 'Откройте ссылку, войдя в свой аккаунт Project Brief. Ссылка действует 30 минут. До подтверждения используется прежний адрес.' : 'Open this link while signed into your Project Brief account. The link expires in 30 minutes. Your previous address stays active until verification.' }}</p>
<p><a href="{{ $verificationUrl }}">{{ $language === 'ru' ? 'Подтвердить адрес' : 'Verify address' }}</a></p>
<p>{{ $language === 'ru' ? 'Если вы не меняли адрес, проигнорируйте это письмо.' : 'If you did not request this change, ignore this email.' }}</p>
</body></html>
