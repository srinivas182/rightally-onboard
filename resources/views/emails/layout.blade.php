<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#EEF2F8;font-family:'Instrument Sans',-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#041527">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF2F8">
<tr><td align="center" style="padding:28px 12px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden">
        <tr><td style="padding:26px 36px;border-bottom:1px solid #E6EBF3">
            <img src="{{ asset('brand/logo.png') }}" alt="RightAlly" height="30" style="height:30px;display:block;border:0">
        </td></tr>
        <tr><td style="padding:32px 36px;font-size:15px;line-height:1.6">
            {!! $content !!}
        </td></tr>
        <tr><td style="padding:20px 36px;background:#F6F8FC;font-size:12px;line-height:1.5;color:#5E6B82">
            {{ $company['legal_name'] }}@if ($company['dba']) d/b/a {{ $company['dba'] }}@endif, {{ $company['address'] }}<br>
            Questions? Reply to this email or write to <a href="mailto:{{ $company['support_email'] }}" style="color:#1457EC">{{ $company['support_email'] }}</a>.<br>
            <a href="{{ route('legal', 'privacy') }}" style="color:#5E6B82">Privacy Policy</a> · <a href="{{ route('legal', 'terms') }}" style="color:#5E6B82">Terms of Use</a>@isset($accountLink) · <a href="{{ $accountLink }}" style="color:#5E6B82">Your account</a>@endisset
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
