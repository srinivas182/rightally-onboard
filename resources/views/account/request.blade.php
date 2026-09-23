@extends('layouts.page')
@section('title', 'Your account')
@section('content')
<div style="max-width:480px">
    <h1 class="h2">Open your account</h1>
    <p class="text-slate">Enter the email you used when you signed up. We’ll send a link to see your agreement, invoices and receipts, and to update your payment method.</p>
    <form method="post" action="{{ route('account.send-link') }}" class="mt-4">@csrf
        <label class="form-label" for="email">Email</label>
        <input type="email" class="form-control mb-3 @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        <button class="btn btn-primary">Email me a link</button>
    </form>
</div>
@endsection
