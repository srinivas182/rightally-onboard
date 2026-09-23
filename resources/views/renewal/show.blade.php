<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>{{ __('Renew your agreement') }} · RightAlly</title>
    <link rel="icon" href="{{ asset('favicon.png') }}">
    @include('partials.fonts')
    @vite(['resources/scss/app.scss', 'resources/js/onboarding.js'])
</head>
<body class="bg-surface">
<a class="skip-link" href="#main">Skip to main content</a>
@include('partials.icons')
@php $signed = $contract->isSigned(); @endphp
<header class="border-bottom"><div class="container py-3" style="max-width:760px"><img src="{{ asset('brand/logo.png') }}" alt="RightAlly" style="height:28px"></div></header>
<main id="main" tabindex="-1" class="container py-4 py-md-5" style="max-width:760px">
    <h1 class="h2">{{ $signed ? __('Your renewal is signed') : __('Renew your RightAlly agreement') }}</h1>
    <p class="lead text-slate">
        @if ($expired) {{ __('This renewal offer has ended. Reply to our email or contact us to continue.') }}
        @elseif ($signed) {{ __('Thank you. Your agreement continues from :start to :end.', ['start' => $contract->starts_on->translatedFormat('F j, Y'), 'end' => $contract->ends_on->translatedFormat('F j, Y')]) }}
        @else {{ __('Your current agreement ends on :date. Review and sign to keep :company running without interruption.', ['date' => $contract->previous->ends_on->translatedFormat('F j, Y'), 'company' => $customer->company_name]) }}
        @endif
    </p>

    <div class="doc-scroll doc mb-4" tabindex="0" aria-label="{{ __('Renewal agreement text') }}" id="agreement-doc">
        <h2>{{ $contract->template->title }}</h2>
        <p class="small text-slate mb-3">{{ __('Agreement :number', ['number' => $contract->number]) }}</p>
        {!! $body !!}
    </div>

    @if ($signed)
        <a class="btn btn-outline-primary" href="{{ $pdfUrl }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>{{ __('Download signed renewal (PDF)') }}</a>
    @elseif (! $expired)
        <div class="sig-card">
            <div class="co-sig mb-4"><div class="small text-slate">{{ __('Signed for :name', ['name' => $contract->company_dba ?: $contract->company_legal_name]) }}</div>
                @if ($companySignature)<img src="{{ $companySignature }}" alt="" style="max-height:56px;max-width:240px">@else<div class="sig-font">{{ $contract->company_signatory_name }}</div>@endif
                <div class="small">{{ $contract->company_signatory_name }}, {{ $contract->company_signatory_title }}</div></div>
            @include('partials.flash')
            <form method="post" action="{{ $signUrl }}" id="signForm" novalidate>@csrf
                <input type="hidden" name="signature" id="signatureData">
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="consent" name="consent" value="1"><label class="form-check-label" for="consent">{{ __('I agree to sign this renewal electronically, and I have read and accept its terms.') }}</label></div>
                <div class="row g-3">
                    <div class="col-sm-7"><label class="form-label" for="typed_name">{{ __('Type your full legal name') }}</label><input class="form-control" id="typed_name" name="typed_name" value="{{ old('typed_name', $customer->fullName()) }}" maxlength="160" required></div>
                    <div class="col-sm-5"><span class="form-label d-block">{{ __('Preview') }}</span><div class="sig-font text-primary text-truncate" id="typedPreview" aria-hidden="true">{{ $customer->fullName() }}</div></div>
                    <div class="col-12"><div class="d-flex justify-content-between align-items-end"><span class="form-label mb-2" id="padLabel">{{ __('Draw your signature') }}</span><button type="button" class="btn btn-link btn-sm p-0 mb-2" id="padClear">{{ __('Clear') }}</button></div>
                        <div id="padBox" class="pad-wrap"><canvas id="pad" aria-labelledby="padLabel" role="img"></canvas><div class="base"></div><div class="hint">{{ __('Sign with your finger, stylus or mouse') }}</div></div>
                    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="useTyped" name="signature_method" value="typed" @checked(old('signature_method') === 'typed')>
                        <label class="form-check-label small" for="useTyped">{{ __('Use my typed name as my signature instead of drawing') }}</label></div></div>
                </div>
                <div class="alert alert-danger small mt-3 d-none" id="signErr" role="alert"></div>
                <button class="btn btn-primary btn-lg px-5 mt-4" type="submit" id="signBtn">{{ __('Sign renewal') }}</button>
            </form>
        </div>
    @endif
</main>
@include('partials.legal-footer')
</body>
</html>
