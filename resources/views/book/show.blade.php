@extends('layouts.page')
@section('title', __('Book a call'))
@section('brand-center', '1')
@section('content')
<div class="text-center mx-auto mb-4" style="max-width:640px">
    <h1 class="h2 mb-2">{{ __('Book a call with RightAlly') }}</h1>
    <p class="text-slate mb-0">{{ __('Pick a time that suits you. We’ll walk you through RightAlly, answer your questions and look at what fits your brokerage.') }}</p>
    @if ($referrer)<p class="mt-3 mb-0"><span class="referred">{{ __('You were referred by :name', ['name' => $referrer]) }}</span></p>@endif
</div>
<div class="panel p-2 p-md-3">
    <iframe src="{{ $src }}" id="{{ $frameId }}" title="{{ __('Choose a time for your call') }}" allow="payment" scrolling="no" style="width:100%;min-height:720px;border:none;overflow:hidden"></iframe>
</div>
<p class="text-center small text-slate mt-4">{{ __('Ready to start now?') }} <a href="{{ url('/').($coupon ? '?coupon='.urlencode($coupon) : '') }}">{{ __('Set up your brokerage') }}</a></p>
<script src="https://link.msgsndr.com/js/form_embed.js" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" type="text/javascript"></script>
@endsection
