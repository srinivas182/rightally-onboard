@extends('layouts.onboarding')
@section('title', 'Review and sign')
@section('content')
@php $signed = $contract->isSigned(); @endphp
<p class="step-kicker">Step 2 of 5</p>
<h1>{{ $signed ? 'Your agreement is signed' : 'Review and sign your agreement' }}</h1>
<p class="lead">{{ $signed ? 'Download a copy for your records, then review your payment schedule.' : 'Read the agreement below. You can download a signed copy as soon as you sign.' }}</p>

@if (session('delegated'))
    <div class="alert alert-info" role="status">{{ session('delegated') }}</div>
@endif
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
            <p class="small text-slate mt-3 mb-0">Not the person who signs for {{ $customer->company_name }}? <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-bs-toggle="modal" data-bs-target="#delegateModal">Send it to someone else to sign</button></p>
        </form>

        <div class="modal fade" id="delegateModal" tabindex="-1" aria-labelledby="delegateTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="{{ route('onboarding.delegate', $customer) }}">@csrf
                <div class="modal-header"><h2 class="modal-title h5" id="delegateTitle">Send to someone else to sign</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <p class="small text-slate">Their name and title go on the agreement, and we email them a link to review and sign. Receipts and account emails still go to {{ $customer->email }}.</p>
                    <div class="row g-3">
                        <div class="col-sm-6"><label class="form-label" for="signer_first_name">First name</label><input class="form-control" id="signer_first_name" name="signer_first_name" required maxlength="80" value="{{ old('signer_first_name') }}"></div>
                        <div class="col-sm-6"><label class="form-label" for="signer_last_name">Last name</label><input class="form-control" id="signer_last_name" name="signer_last_name" required maxlength="80" value="{{ old('signer_last_name') }}"></div>
                        <div class="col-sm-6"><label class="form-label" for="signer_title">Title</label><input class="form-control" id="signer_title" name="signer_title" required maxlength="80" placeholder="e.g. Owner" value="{{ old('signer_title') }}"></div>
                        <div class="col-sm-6"><label class="form-label" for="signer_email">Email</label><input type="email" class="form-control" id="signer_email" name="signer_email" required maxlength="160" value="{{ old('signer_email') }}"></div>
                    </div>
                    @if ($errors->delegate->any())<div class="alert alert-danger small mt-3 mb-0">{{ $errors->delegate->first() }}</div>@endif
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Send signing link</button></div>
            </form></div></div>
        @if ($errors->delegate->any())<div data-open-modal="#delegateModal" hidden></div>@endif
    @endif
</div>
@endsection
