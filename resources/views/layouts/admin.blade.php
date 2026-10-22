<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <script src="{{ $assetv('assets/vodnox/theme.js') }}"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#101D35">
    <link rel="icon" type="image/png" href="{{ asset('assets/vodnox/vodnox-app-icon-gold.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/vodnox/vodnox-app-icon-gold.png') }}">
    <title>@yield('title', 'VODNOX') — @lang('vodnox.platform_console')</title>
    <link rel="stylesheet" href="{{ $assetv('assets/vodnox/fonts.css') }}">
    <link rel="stylesheet" href="{{ $assetv('assets/vodnox/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ $assetv('assets/vodnox/admin.css') }}">
    <link rel="stylesheet" href="{{ $assetv('assets/vodnox/theme.css') }}">
    @stack('styles')
</head>
<body>
<svg class="logo-color-defs" xmlns="http://www.w3.org/2000/svg" width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute;pointer-events:none"><defs><filter id="vodnox-dark-logo" color-interpolation-filters="sRGB" x="0" y="0" width="100%" height="100%"><feColorMatrix type="matrix" values="1 0 0 0 0 1 0 0 0 0 1 0 0 0 0 0 0 0 1 0"/><feComponentTransfer><feFuncR type="discrete" tableValues="1 1"/><feFuncG type="discrete" tableValues="1 0.6235294118"/><feFuncB type="discrete" tableValues="1 0.2078431373"/><feFuncA type="identity"/></feComponentTransfer></filter></defs></svg>

<aside class="sidebar" id="sidebar">
    <a class="brand" href="{{ route('home') }}"><img src="{{ asset('assets/vodnox/vodnox-logo.png') }}" alt="VODNOX"><span>PLATFORM CONSOLE</span></a>
    <div class="nav-label">@lang('vodnox.management')</div>
    @include('partials.admin-nav')
    <div class="side-bottom">
        <div class="environment"><i></i><span>@lang('vodnox.preview')</span><small>@auth{{ auth()->user()->username }}@endauth</small></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">@lang('vodnox.logout')</button></form>
    </div>
</aside>

<div class="app">
    <header class="topbar">
        <div class="breadcrumb">
            <button class="icon-btn mobile-menu" id="menuBtn" aria-label="@lang('vodnox.menu')">☰</button>
            <span>@lang('vodnox.platform')</span>
            <span class="sep">/</span>
            <b id="crumb">@yield('crumb', '')</b>
        </div>
        <div class="top-actions">
            <button type="button" class="theme-toggle" data-theme-toggle aria-label="@lang('vodnox.toggle_theme')" aria-pressed="false"></button>
            <a class="language" href="{{ route('locale.switch', ['lang' => app()->getLocale() === 'ar' ? 'en' : 'ar']) }}">{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</a>
            <span class="top-divider"></span>
            <div class="profile">
                <span class="avatar">{{ mb_substr(auth()->user()->name ?? 'A', 0, 1) }}</span>
                <div><b>@lang('vodnox.owner')</b><small>SUPER ADMIN</small></div>
            </div>
        </div>
    </header>
    <main>
        @yield('content')
        <footer>
            <span>VODNOX <span class="footer-dot">·</span> <span>@lang('vodnox.footer')</span></span>
            <span>© 2026 VODNOX</span>
        </footer>
    </main>
</div>

@include('partials.modal')
<div id="toast" class="app-toast" role="status" aria-live="polite"></div>

<script src="{{ $assetv('assets/vodnox/admin.js') }}"></script>
@stack('scripts')
</body>
</html>
