<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $page->title }} · RightAlly</title>
    <link rel="icon" href="{{ asset('favicon.png') }}">
    @include('partials.fonts')
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="bg-surface">
<a class="skip-link" href="#main">Skip to main content</a>
<header class="border-bottom"><div class="container py-3" style="max-width:760px"><a href="{{ url('/') }}"><img src="{{ asset('brand/logo.png') }}" alt="RightAlly" style="height:28px"></a></div></header>
<main id="main" tabindex="-1" class="container py-4 py-md-5 doc" style="max-width:760px">
    <h1 class="h2 mb-3" style="font-family:var(--bs-body-font-family)">{{ $page->title }}</h1>
    {!! $body !!}
</main>
@include('partials.legal-footer')
</body>
</html>
