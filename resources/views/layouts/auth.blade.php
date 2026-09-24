<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · RightAlly Admin</title>
    <link rel="icon" href="{{ asset('favicon.png') }}">
    @include('partials.fonts')
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>
    <main id="main" tabindex="-1" class="auth">
        <div class="auth-card">
            <div class="auth-brand"><img src="{{ asset('brand/logo-white.png') }}" alt="RightAlly" class="auth-logo"><span class="badge text-bg-primary fw-medium">Admin</span></div>
            @include('partials.flash', ['hideErrorSummary' => true])
            @yield('content')
        </div>
    </main>
</body>
</html>
