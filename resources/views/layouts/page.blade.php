<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · RightAlly</title>
    <link rel="icon" href="{{ asset('favicon.png') }}">
    @include('partials.fonts')
    @vite(['resources/scss/app.scss', 'resources/js/onboarding.js'])
    @stack('head')
</head>
<body class="bg-surface">
<a class="skip-link" href="#main">Skip to main content</a>
@include('partials.icons')
<header class="border-bottom"><div class="container py-3 d-flex justify-content-between align-items-center" style="max-width:860px">
    <a href="{{ url('/') }}"><img src="{{ asset('brand/logo.png') }}" alt="RightAlly" style="height:28px"></a>
    @yield('header-right')
</div></header>
<main id="main" tabindex="-1" class="container py-4 py-md-5" style="max-width:860px">
    @include('partials.flash', ['hideErrorSummary' => View::hasSection('field-errors-only')])
    @yield('content')
</main>
@include('partials.legal-footer')
</body>
</html>
