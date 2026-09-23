<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · RightAlly</title>
    <link rel="icon" href="{{ asset('favicon.png') }}">
    @include('partials.fonts')
    @vite(['resources/scss/app.scss', 'resources/js/onboarding.js'])
    @stack('head')
</head>
<body class="bg-surface">
@include('partials.icons')
@php
    $names = ['Your details', 'Review and sign', 'Payment schedule', 'Payment method', 'All set'];
    $money = fn (int $cents) => \App\Support\Money::format($cents);
@endphp
<div class="ob">
    <aside class="rail" aria-label="Onboarding progress">
        <div class="brand-inv"><img src="{{ asset('brand/icon.png') }}" alt="">RightAlly</div>
        <ol class="steps">
            @foreach ($names as $i => $name)
                @php $n = $i + 1; @endphp
                <li class="{{ $n < $step ? 'done' : ($n === $step ? 'cur' : '') }}" @if ($n === $step) aria-current="step" @endif>
                    <span class="dot">{!! $n < $step ? '&#10003;' : $n !!}</span>{{ $name }}
                </li>
            @endforeach
        </ol>
        @include('onboarding.partials.ledger', ['variant' => 'rail'])
        <div class="rail-foot"><svg class="ic mt-1" aria-hidden="true"><use href="#i-lock"/></svg>Payments are processed securely by Stripe. We never see your card number.</div>
    </aside>

    <main>
        <div class="m-head"><img src="{{ asset('brand/logo.png') }}" alt="RightAlly"><span class="small text-slate">Step {{ $step }} of 5</span></div>
        <div class="m-prog" aria-hidden="true"><i style="width: {{ $step * 20 }}%"></i></div>
        <div class="work"><div class="work-inner">
            @yield('content')
        </div></div>
    </main>
</div>

@if ($step < 5)
    <div class="m-ledger">
        <div><div class="small opacity-75">Due today</div><b class="fs-5 num" data-l="deposit">{{ $money($ledger['deposit']) }}</b></div>
        <button class="btn btn-sm btn-outline-light" type="button" data-bs-toggle="offcanvas" data-bs-target="#ledgerSheet">View agreement</button>
    </div>
    <div class="offcanvas offcanvas-bottom h-auto" tabindex="-1" id="ledgerSheet" aria-labelledby="ledgerSheetTitle">
        <div class="offcanvas-header"><h2 class="offcanvas-title h5" id="ledgerSheetTitle">Your agreement</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>
        <div class="offcanvas-body pt-0">@include('onboarding.partials.ledger', ['variant' => 'sheet'])</div>
    </div>
@endif
@stack('scripts')
</body>
</html>
