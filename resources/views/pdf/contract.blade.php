<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    @font-face { font-family: 'SourceSerif'; font-weight: normal; src: url('{{ $fontDir }}/SourceSerif4-Regular.ttf') format('truetype'); }
    @font-face { font-family: 'SourceSerif'; font-weight: bold; src: url('{{ $fontDir }}/SourceSerif4-Semibold.ttf') format('truetype'); }
    @font-face { font-family: 'Signature'; font-weight: normal; src: url('{{ $fontDir }}/MrsSaintDelafield-Regular.ttf') format('truetype'); }
    @page { margin: 54px 54px 64px 54px; }
    body { font-family: 'SourceSerif', 'DejaVu Serif', serif; font-size: 10.5pt; line-height: 1.32; color: #041527; }
    .sans { font-family: 'DejaVu Sans', sans-serif; }
    .head { border-bottom: 2px solid #041527; padding-bottom: 10px; margin-bottom: 18px; }
    .head table { width: 100%; }
    .head .meta { text-align: right; font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; color: #5E6B82; line-height: 1.5; }
    h1 { font-size: 16pt; font-weight: bold; margin: 0 0 12px; line-height: 1.25; }
    h3 { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; font-weight: bold; margin: 14px 0 3px; page-break-after: avoid; }
    p { margin: 0 0 6px; text-align: left; }
    .fee-box { border: 1.2px solid #1457EC; margin: 12px 0 14px; page-break-inside: avoid; }
    .fee-box .hd { background: #EDF2FD; padding: 5px 10px; font-family: 'DejaVu Sans', sans-serif; font-weight: bold; font-size: 8.5pt; }
    .fee-box table { width: 100%; border-collapse: collapse; font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; }
    .fee-box td { padding: 4px 10px; border-top: 1px solid #DFE5EF; }
    .fee-box td.amt { text-align: right; white-space: nowrap; }
    .sigs { width: 100%; margin-top: 20px; page-break-inside: avoid; }
    .sigs td { width: 50%; vertical-align: bottom; padding-right: 24px; }
    .sig-label { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; color: #5E6B82; }
    .sig-box { height: 52px; position: relative; }
    .sig-font { font-family: 'Signature', cursive; font-size: 28pt; color: #0839B2; position: absolute; left: 0; bottom: -6px; }
    .sig-img { max-height: 50px; max-width: 220px; position: absolute; left: 0; bottom: 2px; }
    .sig-line { border-top: 1px solid #041527; padding-top: 3px; font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; }
    .audit { margin-top: 18px; font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; background: #F6F8FC; border: 1px solid #DFE5EF; padding: 8px 10px; line-height: 1.55; page-break-inside: avoid; }
    .audit b { font-size: 8pt; }
</style>
</head>
<body>
@php $tz = \App\Support\BusinessClock::timezone(); @endphp
<div class="head"><table><tr>
    <td>@if ($logo)<img src="{{ $logo }}" style="height:26px" alt="RightAlly">@endif</td>
    <td class="meta">Agreement {{ $contract->number }}<br>Template v{{ $contract->template->version }}<br>@if ($contract->signed_at)Signed {{ $contract->signed_at->copy()->setTimezone($tz)->format('M j, Y') }}@endif</td>
</tr></table></div>

<h1>{{ $contract->template->title }}</h1>
{!! $body !!}

<table class="sigs"><tr>
    <td>
        <div class="sig-label">For {{ $contract->company_dba ?: $contract->company_legal_name }}</div>
        <div class="sig-box">@if ($companySignature)<img class="sig-img" src="{{ $companySignature }}" alt="">@else<div class="sig-font">{{ $contract->company_signatory_name }}</div>@endif</div>
        <div class="sig-line">{{ $contract->company_signatory_name }}, {{ $contract->company_signatory_title }}<br>{{ $contract->company_legal_name }}@if ($contract->company_dba) d/b/a {{ $contract->company_dba }}@endif<br>{{ $contract->signed_at?->copy()->setTimezone($tz)->format('F j, Y') }}</div>
    </td>
    <td>
        <div class="sig-label">For {{ $customer->company_name }}</div>
        <div class="sig-box">@if ($clientSignature)<img class="sig-img" src="{{ $clientSignature }}" alt="">@else<div class="sig-font">{{ $contract->client_typed_name }}</div>@endif</div>
        <div class="sig-line">{{ $contract->client_typed_name }}, {{ $contract->client_title }}<br>{{ $customer->company_name }}<br>{{ $contract->signed_at?->copy()->setTimezone($tz)->format('F j, Y') }}</div>
    </td>
</tr></table>

@if ($contract->signed_at)
<div class="audit">
    <b>Electronic signature record</b><br>
    Signer: {{ $contract->client_typed_name }} ({{ $contract->signer_email ?? $customer->email }}), who typed their name and drew their signature.@if ($contract->signature_requested_at) Signing link sent to the signer by email {{ $contract->signature_requested_at->copy()->setTimezone($tz)->format('M j, Y g:i A T') }}.@endif<br>
    Consent to sign electronically: {{ $contract->esign_consent_at->copy()->setTimezone($tz)->format('M j, Y g:i:s A T') }}.
    Signed: {{ $contract->signed_at->copy()->setTimezone($tz)->format('M j, Y g:i:s A T') }} from IP {{ $contract->signer_ip }}.<br>
    Device: {{ \Illuminate\Support\Str::limit((string) $contract->signer_user_agent, 150) }}<br>
    Terms fingerprint (SHA-256 of the agreement text above): {{ hash('sha256', (string) $contract->rendered_html) }}<br>
    Agreement ID: {{ $contract->uuid }}
</div>
@endif
</body>
</html>
