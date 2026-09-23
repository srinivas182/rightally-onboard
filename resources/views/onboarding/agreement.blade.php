@extends('layouts.onboarding')
@section('title', 'Review and sign')
@section('content')
@php $signed = $contract->isSigned(); @endphp
<p class="step-kicker">Step 2 of 5</p>
<h1>{{ $signed ? 'Your agreement is signed' : 'Review and sign your agreement' }}</h1>
<p class="lead">{{ $signed ? 'Download a copy for your records, then review your payment schedule.' : 'Read the agreement below. You can download a signed copy as soon as you sign.' }}</p>

@if ($signed)
    <div class="alert alert-success d-flex gap-2 align-items-start" role="status">
        <svg class="ic mt-1" aria-hidden="true"><use href="#i-check"/></svg>
        <div><b>Signed {{ $contract->signed_at->setTimezone(\App\Support\BusinessClock::timezone())->format('M j, Y \a\t g:i A T') }}.</b> Agreement {{ $contract->number }}.</div>
    </div>
@endif

<nav class="toc" aria-label="Agreement sections">
    <a href="#agreement-doc">Fees</a>
    @foreach (['Go-Live Date' => '4', 'Term' => '5', 'Non-payment' => '6', 'Early termination' => '7', 'Refunds' => '8', 'Florida law' => '14'] as $label => $n)
        <a href="#sec-{{ $n }}" data-sec="{{ $n }}">{{ $label }}</a>
    @endforeach
</nav>

<div class="doc-scroll doc" tabindex="0" aria-label="Agreement text" id="agreement-doc">
    <h2>{{ $contract->template->title }}</h2>
    <p class="small text-slate mb-3">Agreement {{ $contract->number }}</p>
    {!! $body !!}
</div>

<div class="sig-card mt-4">
    <div class="co-sig mb-4">
        <div class="small text-slate">Signed for {{ $contract->company_dba ?: $contract->company_legal_name }}</div>
        @if ($companySignature)
            <img src="{{ $companySignature }}" alt="{{ $contract->company_signatory_name }} signature" style="max-height:56px;max-width:240px">
        @else
            <div class="sig-font">{{ $contract->company_signatory_name }}</div>
        @endif
        <div class="small">{{ $contract->company_signatory_name }}, {{ $contract->company_signatory_title }}, {{ $contract->company_legal_name }}{{ $contract->company_dba ? ' d/b/a '.$contract->company_dba : '' }}</div>
    </div>

    @if ($signed)
        <div class="small text-slate">Signed for {{ $customer->company_name }}</div>
        <div class="sig-font text-primary">{{ $contract->client_typed_name }}</div>
        <div class="small mb-4">{{ $contract->client_typed_name }}, {{ $contract->client_title }}</div>
        <div class="d-flex flex-column flex-sm-row gap-2">
            <a class="btn btn-outline-primary" href="{{ route('onboarding.pdf', $customer) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>Download signed agreement (PDF)</a>
            <a class="btn btn-primary px-5" href="{{ route('onboarding.schedule', $customer) }}">Continue to payment schedule</a>
        </div>
    @else
        @include('partials.flash')
        <form method="post" action="{{ route('onboarding.sign', $customer) }}" id="signForm" novalidate>
            @csrf
            <input type="hidden" name="signature" id="signatureData">
            <div class="form-check mb-3">
                <input class="form-check-input @error('consent') is-invalid @enderror" type="checkbox" id="consent" name="consent" value="1" @checked(old('consent'))>
                <label class="form-check-label" for="consent">I agree to sign this agreement electronically, and I have read and accept its terms.</label>
            </div>
            <div class="row g-3">
                <div class="col-sm-7"><label class="form-label" for="typed_name">Type your full legal name</label>
                    <input class="form-control @error('typed_name') is-invalid @enderror" id="typed_name" name="typed_name" value="{{ old('typed_name', $customer->fullName()) }}" maxlength="160" autocomplete="name" required></div>
                <div class="col-sm-5"><span class="form-label d-block">Preview</span><div class="sig-font text-primary text-truncate" id="typedPreview" aria-hidden="true">{{ old('typed_name', $customer->fullName()) }}</div></div>
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-end"><span class="form-label mb-2" id="padLabel">Draw your signature</span><button type="button" class="btn btn-link btn-sm p-0 mb-2" id="padClear">Clear</button></div>
                    <div class="pad-wrap @error('signature') is-invalid @enderror"><canvas id="pad" aria-labelledby="padLabel" role="img"></canvas><div class="base"></div><div class="hint">Sign with your finger, stylus or mouse</div></div>
                </div>
            </div>
            <div class="alert alert-danger small mt-3 d-none" id="signErr" role="alert"></div>
            <div class="d-flex flex-column flex-sm-row gap-2 mt-4">
                <button class="btn btn-primary btn-lg px-5" type="submit" id="signBtn">Sign agreement</button>
                <a class="btn btn-link" href="{{ route('onboarding.details', $customer) }}">Back to details</a>
            </div>
        </form>
    @endif
</div>
@endsection
