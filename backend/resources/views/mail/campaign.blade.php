<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f2ee;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;color:#444;">
@if ($preheader !== '')
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader }}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f2ee;">
<tr><td align="center" style="padding:24px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
    <tr><td style="padding:0 8px 16px;font-size:22px;font-weight:700;color:#242424;">{{ $shop }}</td></tr>
    <tr><td style="background:#ffffff;border-radius:10px;padding:28px 28px 8px;font-size:16px;line-height:1.65;">
      <div class="body">{!! $body_html !!}</div>
    </td></tr>
    <tr><td style="background:#ffffff;border-radius:0 0 10px 10px;height:20px;line-height:20px;">&nbsp;</td></tr>
    <tr><td style="padding:20px 8px;font-size:12px;line-height:1.6;color:#777;text-align:center;">
      {{ $reason }}<br>
      <a href="{{ $unsubscribe_url }}" style="color:#777;text-decoration:underline;">{{ $unsubscribe_label }}</a>
      @if ($address !== '')<br>{{ $shop }} · {{ $address }}@endif
    </td></tr>
  </table>
</td></tr>
</table>
<style>
.body h1,.body h2,.body h3{color:#242424;line-height:1.25;margin:1.2em 0 .5em}
.body a{color:{{ $accent }};}
.body img{max-width:100%;height:auto;border-radius:8px}
.body blockquote{border-left:4px solid {{ $accent }};margin:1em 0;padding:.2em 1em;color:#555}
</style>
</body>
</html>
