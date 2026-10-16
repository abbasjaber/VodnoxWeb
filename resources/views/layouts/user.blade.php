<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <script src="{{ asset('assets/vodnox/theme.js') }}"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#101D35">
    <link rel="icon" type="image/png" href="{{ asset('assets/vodnox/vodnox-app-icon-gold.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/vodnox/vodnox-app-icon-gold.png') }}">
    <title>@yield('title', 'VODNOX') — @lang('vodnox.connection_space')</title>
    <link rel="stylesheet" href="{{ asset('assets/vodnox/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/user.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vodnox/theme.css') }}">
    @stack('styles')
</head>
<body>
<svg class="logo-color-defs" xmlns="http://www.w3.org/2000/svg" width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute;pointer-events:none"><defs><filter id="vodnox-dark-logo" color-interpolation-filters="sRGB" x="0" y="0" width="100%" height="100%"><feColorMatrix type="matrix" values="1 0 0 0 0 1 0 0 0 0 1 0 0 0 0 0 0 0 1 0"/><feComponentTransfer><feFuncR type="discrete" tableValues="1 1"/><feFuncG type="discrete" tableValues="1 0.6235294118"/><feFuncB type="discrete" tableValues="1 0.2078431373"/><feFuncA type="identity"/></feComponentTransfer></filter></defs></svg>

<aside class="sidebar" id="sidebar">
    <a class="brand" href="{{ route('home') }}"><img src="{{ asset('assets/vodnox/vodnox-logo.png') }}" alt="VODNOX"><span>YOUR CONNECTION SPACE</span></a>
    <div class="user-identity">
        <span class="self-avatar">{{ mb_substr(auth()->user()->name ?? '0', 0, 1) }}</span>
        <h2>{{ auth()->user()->name }}</h2>
        <p>{{ auth()->user()->organization?->name_ar ?? auth()->user()->organization?->name_en ?? '-' }}</p>
        <div class="extension-chip"><span>@lang('vodnox.extension')</span><b>{{ auth()->user()->extension ?? '-' }}</b></div>
    </div>
    @include('partials.user-nav')
    <div class="side-bottom">
        <div class="device-state"><span id="deviceIcon"></span><div><strong>@lang('vodnox.unconnected')</strong><small>@lang('vodnox.sip_unverified')</small></div></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">@lang('vodnox.logout')</button></form>
    </div>
</aside>

<div class="app">
    <header class="topbar">
        <div class="breadcrumb">
            <button class="icon-btn mobile-menu" id="menuBtn" aria-label="@lang('vodnox.menu')">☰</button>
            <span>@lang('vodnox.connection_space')</span>
            <span class="sep">/</span>
            <b id="crumb">@yield('crumb', '')</b>
        </div>
        <div class="top-actions">
            <button type="button" class="theme-toggle" data-theme-toggle aria-label="@lang('vodnox.toggle_theme')" aria-pressed="false"></button>
            <a class="language" href="{{ route('locale.switch', ['lang' => app()->getLocale() === 'ar' ? 'en' : 'ar']) }}">{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</a>
            <span class="top-divider"></span>
            <span class="top-extension">{{ auth()->user()->extension ?? '-' }}</span>
        </div>
    </header>
    <main>
        @yield('content')
        <footer>
            <span>VODNOX <span class="footer-dot">·</span> <span>@lang('vodnox.footer')</span></span>
            <span>{{ auth()->user()->organization?->code ?? '' }} / EXT. {{ auth()->user()->extension ?? '-' }}</span>
        </footer>
    </main>
</div>

<nav class="mobile-nav" id="mobileNav"></nav>
<dialog id="callWindow" class="call-window" aria-labelledby="callHeading"><div id="callContent"></div></dialog>
@include('partials.modal')
<div id="toast" class="app-toast" role="status" aria-live="polite"></div>

<script src="{{ asset('assets/vodnox/user-icons.js') }}"></script>
<script src="{{ asset('assets/vodnox/user.js') }}"></script>
@stack('scripts')
</body>
</html>
