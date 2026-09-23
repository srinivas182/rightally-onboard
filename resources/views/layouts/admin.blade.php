<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · RightAlly Admin</title>
    <link rel="icon" href="{{ asset('favicon.png') }}">
    @include('partials.fonts')
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>
@include('partials.icons')
@php
    $admin = auth('admin')->user();
    $current = trim($__env->yieldContent('menu'));
    $stripeMode = app(\App\Services\Settings\SettingsService::class)->get('stripe', 'mode');
@endphp

@php
    $nav = function () use ($navMenus, $current) {
        $html = '';
        $group = null;
        foreach ($navMenus as $key => $m) {
            if ($m['group'] === 'admin' && $group !== 'admin') {
                $html .= '<div class="nav-grp">Administration</div>';
            }
            $group = $m['group'];
            $label = e($m['label']);
            $icon = '<svg class="ic" aria-hidden="true"><use href="#i-'.e($m['icon']).'"/></svg>';
            if (isset($m['sprint'])) {
                $html .= '<span class="nav-a opacity-50" aria-disabled="true" title="Arrives in Sprint '.$m['sprint'].'">'.$icon.$label.'<span class="badge text-bg-secondary fw-normal">Soon</span></span>';
            } else {
                $on = $current === $key ? ' on' : '';
                $aria = $on ? ' aria-current="page"' : '';
                $html .= '<a class="nav-a'.$on.'" href="'.route($m['route']).'"'.$aria.'>'.$icon.$label.'</a>';
            }
        }
        return $html;
    };
@endphp

<div class="adm">
    <nav class="side" aria-label="Admin menu">
        <div class="brand-inv"><img src="{{ asset('brand/icon.png') }}" alt="">RightAlly <span class="badge text-bg-primary fw-medium ms-1" style="font-size:.65rem">Admin</span></div>
        {!! $nav() !!}
        <form method="post" action="{{ route('admin.logout') }}" class="mt-auto">@csrf
            <button class="nav-a" type="submit"><svg class="ic" aria-hidden="true"><use href="#i-out"/></svg>Sign out</button>
        </form>
    </nav>

    <div class="min-w-0">
        <header class="topb">
            <button class="btn btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#admMenu" aria-label="Open menu"><svg class="ic"><use href="#i-menu"/></svg></button>
            <img src="{{ asset('brand/logo.png') }}" alt="RightAlly" class="d-lg-none" style="height:24px">
            <span class="ms-auto"></span>
            @if ($stripeMode === 'test')
                <span class="badge text-bg-warning">Stripe test mode</span>
            @else
                <span class="badge text-bg-success">Live payments</span>
            @endif
            <div class="dropdown">
                <button class="btn btn-link text-reset text-decoration-none d-flex align-items-center gap-2 p-0" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar">{{ $admin->initials() }}</span>
                    <span class="d-none d-md-inline small text-start lh-sm">{{ $admin->name }}<br><span class="text-slate">{{ $admin->role->name }}</span></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="{{ route('admin.account') }}">My account</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><form method="post" action="{{ route('admin.logout') }}">@csrf<button class="dropdown-item" type="submit">Sign out</button></form></li>
                </ul>
            </div>
        </header>

        <div class="offcanvas offcanvas-start" tabindex="-1" id="admMenu" style="background:var(--ra-navy);width:270px" aria-label="Admin menu">
            <div class="offcanvas-body d-flex flex-column">
                <div class="brand-inv mb-3"><img src="{{ asset('brand/icon.png') }}" alt="">RightAlly</div>
                {!! $nav() !!}
                <form method="post" action="{{ route('admin.logout') }}" class="mt-auto">@csrf
                    <button class="nav-a" type="submit"><svg class="ic" aria-hidden="true"><use href="#i-out"/></svg>Sign out</button>
                </form>
            </div>
        </div>

        <main class="main" id="main" tabindex="-1">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
